<?php

declare(strict_types=1);

namespace App\Services\Sandbox;

use App\Contracts\Sandbox\{
    SandboxManagerInterface
};
use App\Exceptions\Sandbox\SandboxCreationException;
use App\Exceptions\Sandbox\SandboxException;
use App\Exceptions\Sandbox\SandboxNotFoundException;
use App\Exceptions\Sandbox\SandboxTimeoutException;
use App\Services\Sandbox\DTO\ExecutionResult;
use App\Services\Sandbox\DTO\SandboxInstance;
use App\Services\Sandbox\Traits\SandboxLoggerTrait;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Docker-based sandbox manager implementation
 *
 * Manages Docker containers through a dedicated sandbox-manager container
 * that has access to the Docker daemon via socket mount.
 */
class DockerSandboxManager implements SandboxManagerInterface
{
    use SandboxLoggerTrait;

    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly Filesystem $files,
        protected Config $config,
        protected ?string $managerScriptPath = null,
        protected ?string $managerContainer = null
    ) {
        $this->managerScriptPath = $managerScriptPath
            ?? $config->get('sandbox.manager.script_path');

        $this->managerContainer = $managerContainer
            ?? $config->get('sandbox.manager.container_name')
            ?? null; // Will auto-detect

        $this->log('Sandbox manager initialized', [
            'container' => $this->managerContainer ?? 'auto-detect',
            'script_path' => $this->managerScriptPath
        ]);
    }

    /**
     * Get or detect the sandbox manager container name
     *
     * @return string
     */
    private function getManagerContainer(): string
    {
        $defaultContainer = $this->config->get('sandbox.manager.container_name', 'depthnet-sandbox-manager-1');

        // If container name was provided in constructor and it's not the default, use it
        if ($this->managerContainer !== null && $this->managerContainer !== $defaultContainer) {
            return $this->managerContainer;
        }

        // Try to auto-detect if using default name or not set
        try {
            $detected = $this->detectManagerContainer();
            if ($detected) {
                $this->managerContainer = $detected;
                return $detected;
            }
        } catch (\Exception $e) {
            $this->log('Auto-detection failed, using configured name', [
                'error' => $e->getMessage(),
                'fallback' => $this->managerContainer
            ], 'warning');
        }

        // fallback
        return $this->managerContainer ?? $defaultContainer;
    }

    /**
     * Detect the sandbox-manager container name
     *
     * @return string|null
     */
    private function detectManagerContainer(): ?string
    {
        $pattern = $this->getManagerPattern();
        $command = sprintf('docker ps --filter "name=%s" --format "{{.Names}}" 2>/dev/null', $pattern);
        $result = shell_exec($command);

        if ($result === null || trim($result) === '') {
            return null;
        }

        $containerName = trim($result);

        // Take the first line if multiple containers match
        $lines = explode("\n", $containerName);
        $containerName = trim($lines[0]);

        if (empty($containerName)) {
            return null;
        }

        $this->log('Auto-detected sandbox manager container', ['container' => $containerName]);

        return $containerName;
    }

    /**
     * @inheritDoc
     */
    public function createSandbox(?string $type = null, ?string $name = null, ?string $ports = null): SandboxInstance
    {
        $type = $type ?? $this->config->get('sandbox.sandbox.default_type', 'ubuntu-full');
        $name = $name ?: $this->generateSandboxName();
        $ports = $ports ? $ports : '';

        $this->log('Creating sandbox', [
            'type' => $type,
            'name' => $name,
            'ports' => $ports
        ]);

        $command = sprintf('%s create %s %s %s', $this->managerScriptPath, $type, $name, $ports);

        $this->logger->info($command);
        $result = $this->executeManagerCommand(trim($command), 3200);

        if ($result['exit_code'] !== 0) {
            throw new SandboxCreationException(
                "Failed to create sandbox: {$result['error']}",
                null,
                null,
                $name
            );
        }

        $containerName = $this->getSandboxPrefix() . '-' . $name;

        return new SandboxInstance(
            id: $name,
            name: $containerName,
            type: $type,
            status: 'running',
            image: "sandbox-{$type}",
            createdAt: new \DateTimeImmutable(),
            metadata: ['container_name' => $containerName]
        );
    }

    /**
     * @inheritDoc
     */
    public function startSandbox(string $sandboxId): bool
    {
        $this->log('Starting sandbox', ['sandbox_id' => $sandboxId]);

        $command = sprintf('%s start %s', $this->managerScriptPath, $sandboxId);
        $result = $this->executeManagerCommand($command);

        if ($result['exit_code'] !== 0) {
            throw new SandboxException(
                "Failed to start sandbox: {$result['error']}",
                0,
                null,
                $sandboxId
            );
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function stopSandbox(string $sandboxId, ?int $timeout = null): bool
    {
        $timeout = $timeout ?? $this->config->get('sandbox.sandbox.default_timeout', 10);

        if ($this->isProtectedContainer($sandboxId)) {
            $current = $this->getCurrentContainer();
            $this->log('Attempted to stop protected container', [
                'sandbox_id' => $sandboxId,
                'current_container' => $current
            ], 'warning');

            throw new SandboxException(
                "Cannot stop protected container. This would terminate the sandbox manager itself.",
                0,
                null,
                $sandboxId
            );
        }

        $this->log('Stopping sandbox', [
            'sandbox_id' => $sandboxId,
            'timeout' => $timeout
        ]);

        $command = sprintf('%s stop %s %d', $this->managerScriptPath, $sandboxId, $timeout);
        $result = $this->executeManagerCommand($command);

        if ($result['exit_code'] !== 0) {
            throw new SandboxException(
                "Failed to stop sandbox: {$result['error']}",
                0,
                null,
                $sandboxId
            );
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function executeCommand(
        string $sandboxId,
        string $command,
        string $user = 'sandbox-user',
        ?int $timeout = null
    ): ExecutionResult {
        $timeout = $timeout ?? $this->config->get('sandbox.sandbox.default_timeout', 30);

        if (!$this->sandboxExists($sandboxId)) {
            throw new SandboxNotFoundException("Sandbox '{$sandboxId}' not found");
        }

        $this->log('Executing command in sandbox', [
            'sandbox_id' => $sandboxId,
            'command' => $command,
            'user' => $user,
            'timeout' => $timeout
        ]);

        $escapedCommand = escapeshellarg($command);
        $managerCommand = sprintf(
            '%s exec %s %s %s %d',
            $this->managerScriptPath,
            $sandboxId,
            $escapedCommand,
            $user,
            $timeout
        );

        $startTime = microtime(true);
        $result = $this->executeManagerCommand($managerCommand, $timeout + 5);
        $executionTime = microtime(true) - $startTime;

        $timedOut = $result['exit_code'] === 124; // timeout exit code

        if ($timedOut) {
            throw new SandboxTimeoutException(
                "Command execution timed out after {$timeout}s",
                124,
                null
            );
        }

        return new ExecutionResult(
            output: $result['output'],
            error: $result['error'],
            exitCode: $result['exit_code'],
            executionTime: $executionTime,
            timedOut: $timedOut
        );
    }

    /**
     * @inheritDoc
     */
    public function resetSandbox(string $sandboxId, ?string $type = null): SandboxInstance
    {
        $type = $type ?? $this->config->get('sandbox.sandbox.default_type', 'ubuntu-full');

        $this->log('Resetting sandbox', [
            'sandbox_id' => $sandboxId,
            'type' => $type
        ]);

        $command = sprintf('%s reset %s %s', $this->managerScriptPath, $sandboxId, $type);
        $result = $this->executeManagerCommand($command);

        if ($result['exit_code'] !== 0) {
            throw new SandboxException(
                "Failed to reset sandbox: {$result['error']}",
                0,
                null,
                $sandboxId
            );
        }

        $containerName = $this->getSandboxPrefix() . '-' . $sandboxId;

        return new SandboxInstance(
            id: $sandboxId,
            name: $containerName,
            type: $type,
            status: 'running',
            image: "sandbox-{$type}",
            createdAt: new \DateTimeImmutable(),
            metadata: ['container_name' => $containerName, 'reset' => true]
        );
    }

    /**
     * @inheritDoc
     */
    public function destroySandbox(string $sandboxId): bool
    {
        if ($this->isProtectedContainer($sandboxId)) {
            $current = $this->getCurrentContainer();
            $this->log('Attempted to destroy protected container', [
                'sandbox_id' => $sandboxId,
                'current_container' => $current
            ], 'warning');

            throw new SandboxException(
                "Cannot destroy protected container. This would terminate the sandbox manager itself.",
                0,
                null,
                $sandboxId
            );
        }

        $this->log('Destroying sandbox', ['sandbox_id' => $sandboxId]);

        $command = sprintf('%s destroy %s', $this->managerScriptPath, $sandboxId);
        $result = $this->executeManagerCommand($command);

        return $result['exit_code'] === 0;
    }

    /**
     * @inheritDoc
     */
    public function getSandbox(string $sandboxId): ?SandboxInstance
    {
        $sandboxes = $this->listSandboxes(true);

        foreach ($sandboxes as $sandbox) {
            if ($sandbox->id === $sandboxId) {
                return $sandbox;
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function listSandboxes(bool $includeAll = false): array
    {
        $listParam = $includeAll ? 'all' : '';
        $command = sprintf('%s list %s', $this->managerScriptPath, $listParam);
        $result = $this->executeManagerCommand($command);

        // Check if we have output even if exit code is not 0
        if ($result['exit_code'] !== 0 && empty($result['output'])) {
            $this->log('Failed to list sandboxes', [
                'exit_code' => $result['exit_code'],
                'error' => $result['error']
            ], 'warning');
            return [];
        }

        $current = $this->getCurrentContainer();
        $resultFinal = [];
        $resultRaw = $this->parseSandboxList($result['output']);

        $managerPattern = $this->getManagerPattern();
        $sandboxPrefix = $this->getSandboxPrefix();

        foreach ($resultRaw as $item) {
            // Skip if a a manager container
            if (str_contains($item->name, $managerPattern) || str_contains($item->name, 'sandbox-manager')) {
                continue;
            }

            // Skip if current container
            if ($current && (
                str_contains($item->name, $current) ||
                str_contains($current, $item->name) ||
                $item->name === $current
            )) {
                continue;
            }

            // Only containers with our sandbox prefix
            if (str_starts_with($item->name, $sandboxPrefix . '-')) {
                $resultFinal[] = $item;
            }
        }

        return $resultFinal;
    }

    /**
     * @inheritDoc
     */
    public function sandboxExists(string $sandboxId): bool
    {
        return $this->getSandbox($sandboxId) !== null;
    }

    /**
     * @inheritDoc
     */
    public function cleanupAll(): int
    {
        $this->log('Cleaning up all sandboxes');

        $sandboxes = $this->listSandboxes(true);
        $count = count($sandboxes);

        $command = sprintf('%s cleanup', $this->managerScriptPath);
        $this->executeManagerCommand($command);

        return $count;
    }

    /**
     * @inheritDoc
     */
    public function getSandboxTypes(?string $path = null): array
    {
        if (!$path) {
            $templatesDir = $this->config->get('sandbox.sandbox.templates_dir', 'sandboxes/templates');
            $path = base_path($templatesDir);
        }

        $sandboxTypes = [];

        foreach ($this->files->files($path) as $file) {
            if ($file->getExtension() !== 'dockerfile') {
                continue;
            }

            $filename = $file->getFilenameWithoutExtension();
            $content = $this->files->get($file->getPathname());

            if (preg_match('/TEMPLATE DESCRIPTION:\s*(.+)/i', $content, $matches)) {
                $description = trim($matches[1]);
            } else {
                $description = null;
            }

            $sandboxTypes[$filename] = $description;
        }

        return $sandboxTypes;
    }

    /**
     * Execute sandbox manager script command via docker exec
     *
     * @param string $command
     * @param integer $timeout
     * @return array
     */
    private function executeManagerCommand(string $command, int $timeout = 60): array
    {
        $this->log('Executing manager command', ['command' => $command], 'debug');

        $managerContainer = $this->getManagerContainer();

        // First, lets test if script exists and is executable
        $testCommand = sprintf(
            'docker exec %s test -x %s && echo "OK" || echo "FAIL"',
            escapeshellarg($managerContainer),
            escapeshellarg($this->managerScriptPath)
        );

        $testResult = shell_exec($testCommand);
        $this->log('Script test result', ['result' => trim($testResult ?? '')], 'debug');

        // Split command into parts for proper docker exec
        $parts = explode(' ', $command, 2);
        $scriptPath = $parts[0];
        $args = $parts[1] ?? '';

        // Execute command in sandbox-manager container via docker exec
        $dockerCommand = sprintf(
            'docker exec %s %s %s',
            escapeshellarg($managerContainer),
            escapeshellarg($scriptPath),
            $args // Don't escape args as they may contain multiple parameters
        );

        $this->log('Full docker command', ['docker_command' => $dockerCommand], 'debug');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($dockerCommand, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new SandboxException("Failed to execute command: {$dockerCommand}");
        }

        fclose($pipes[0]);

        $output = '';
        $error = '';
        $startTime = time();

        // Read all output first
        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            if (empty($read)) {
                break;
            }

            $write = [];
            $except = [];

            if (stream_select($read, $write, $except, 1) > 0) {
                foreach ($read as $stream) {
                    $data = fread($stream, 8192);
                    if ($data !== false && $data !== '') {
                        if ($stream === $pipes[1]) {
                            $output .= $data;
                        } else {
                            $error .= $data;
                        }
                    }
                }
            }

            // Check for timeout
            if (time() - $startTime > $timeout) {
                proc_terminate($process, SIGTERM);
                sleep(1);
                proc_terminate($process, SIGKILL);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new SandboxTimeoutException("Manager command timed out: {$command}", $timeout);
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $output = $output ?? '';
        $error = $error ?? '';

        $this->log('Command execution result', [
            'exit_code' => $exitCode,
            'output' => $output,
            'error' => $error
        ], 'debug');

        return [
            'output' => trim($output),
            'error' => trim($error),
            'exit_code' => $exitCode
        ];
    }

    /**
     * Parse sandbox list output from manager script (fixed for 4-column format)
     *
     * @param string $output
     * @return array
     */
    private function parseSandboxList(string $output): array
    {
        $lines = explode("\n", $output);
        $sandboxes = [];
        $sandboxPrefix = $this->getSandboxPrefix();

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines, info messages, and headers
            if (empty($line) ||
                str_starts_with($line, 'Info:') ||
                str_contains($line, 'NAMES') ||
                str_contains($line, 'Active sandboxes') ||
                str_contains($line, 'All sandboxes')) {
                continue;
            }

            // Parse container line - try new 4-column format first
            // New format: "container-name   status   image-name   ports"
            if (preg_match('/^(\S+)\s+(\S+)\s+(\S+)\s+(.*)$/', $line, $matches)) {
                $containerName = trim($matches[1]);
                $status = trim($matches[2]);
                $image = trim($matches[3]);
                $ports = trim($matches[4]);

                $this->log('Parsing new 4-column format', [
                    'line' => $line,
                    'container' => $containerName,
                    'status' => $status,
                    'image' => $image,
                    'ports' => $ports
                ], 'debug');

            } elseif (preg_match('/^(\S+)\s+(.+?)\s+(\S+)$/', $line, $matches)) {
                // Fallback to old 3-column format: "container-name   status (can have spaces)   image-name"
                $containerName = trim($matches[1]);
                $status = trim($matches[2]);
                $image = trim($matches[3]);
                $ports = 'none'; // No ports in old format

                $this->log('Parsing old 3-column format', [
                    'line' => $line,
                    'container' => $containerName,
                    'status' => $status,
                    'image' => $image
                ], 'debug');

            } else {
                $this->log('Could not parse sandbox line', ['line' => $line], 'debug');
                continue;
            }

            // Extract sandbox ID from container name
            if (str_starts_with($containerName, $sandboxPrefix . '-')) {
                $sandboxId = substr($containerName, strlen($sandboxPrefix . '-'));

                // Determine sandbox type from image
                $type = str_replace('sandbox-', '', $image);
                if ($type === $image) {
                    // Fallback if image doesn't start with 'sandbox-'
                    $type = 'unknown';
                }

                // Parse ports
                $parsedPorts = $this->parsePorts($ports);

                $sandboxes[] = new SandboxInstance(
                    id: $sandboxId,
                    name: $containerName,
                    type: $type,
                    status: $this->normalizeStatus($status),
                    image: $image,
                    createdAt: new \DateTimeImmutable(),
                    metadata: [
                        'container_name' => $containerName,
                        'ports' => $parsedPorts
                    ]
                );

                $this->log('Parsed sandbox', [
                    'id' => $sandboxId,
                    'name' => $containerName,
                    'status' => $status,
                    'normalized_status' => $this->normalizeStatus($status),
                    'type' => $type,
                    'ports' => $parsedPorts
                ], 'debug');
            }
        }

        $this->log('Parsed sandboxes total', ['count' => count($sandboxes)], 'debug');

        return $sandboxes;
    }

    /**
     * Parse ports string into array
     *
     * @param string $portsString
     * @return array
     */
    private function parsePorts(string $portsString): array
    {
        $portsString = trim($portsString);

        if (empty($portsString) || $portsString === 'none') {
            return [];
        }

        $ports = [];
        $portNumbers = explode(',', $portsString);

        foreach ($portNumbers as $port) {
            $port = trim($port);
            if (is_numeric($port) && $port > 0 && $port <= 65535) {
                $ports[] = (int) $port;
            }
        }

        return $ports;
    }

    /**
     * Normalize Docker status to our status format
     *
     * @param string $dockerStatus
     * @return string
     */
    private function normalizeStatus(string $dockerStatus): string
    {
        $dockerStatus = trim($dockerStatus);

        // Handle direct status values
        if (in_array($dockerStatus, ['running', 'stopped', 'exited', 'created', 'paused', 'restarting'])) {
            switch ($dockerStatus) {
                case 'running':
                case 'restarting':
                    return 'running';
                case 'stopped':
                case 'exited':
                case 'created':
                case 'paused':
                    return 'stopped';
                default:
                    return $dockerStatus;
            }
        }

        // Handle Docker-style status messages (on legacy)

        // Check for running status (starts with "Up")
        if (str_starts_with($dockerStatus, 'Up')) {
            return 'running';
        }

        // Check for stopped/exited status
        if (str_starts_with($dockerStatus, 'Exited') ||
            str_starts_with($dockerStatus, 'Stopped')) {
            return 'stopped';
        }

        // Other Docker statuses
        if (str_starts_with($dockerStatus, 'Created')) {
            return 'stopped';
        }

        if (str_starts_with($dockerStatus, 'Restarting')) {
            return 'running';
        }

        if (str_starts_with($dockerStatus, 'Paused')) {
            return 'stopped';
        }

        // Log unknown status for debugging
        $this->log('Unknown Docker status', [
            'status' => $dockerStatus,
            'original' => $dockerStatus
        ], 'warning');

        return 'unknown';
    }

    /**
     * Generate unique sandbox name
     *
     * @return string
     */
    private function generateSandboxName(): string
    {
        return 'auto-' . uniqid();
    }

    /**
     * @inheritDoc
     */
    public function getStats(array $currentList = []): array
    {
        if (empty($currentList)) {
            $currentList = $this->listSandboxes(true); // Include all for accurate stats
        }

        return [
            'total' => count($currentList),
            'running' => count(array_filter($currentList, fn ($s) => $s->status === 'running')),
            'stopped' => count(array_filter($currentList, fn ($s) => $s->status === 'stopped'))
        ];
    }

    /**
     * @inheritDoc
     */
    public function getCurrentContainer(): ?string
    {
        $command = sprintf('%s current', $this->managerScriptPath);

        try {
            $result = $this->executeManagerCommand($command);

            if ($result['exit_code'] === 0 && !empty($result['output'])) {
                return trim($result['output']);
            }
        } catch (\Exception $e) {
            $this->log('Failed to detect current container', [
                'error' => $e->getMessage()
            ], 'debug');
        }

        return null;
    }

    /**
     * Check if container is protected from deletion
     *
     * @param string $sandboxId
     * @return boolean
     */
    protected function isProtectedContainer(string $sandboxId): bool
    {
        $current = $this->getCurrentContainer();

        if (!$current) {
            return false;
        }

        $containerName = $this->getSandboxPrefix() . '-' . $sandboxId;
        $managerPattern = $this->getManagerPattern();

        // Check if trying to delete current container or manager
        return str_contains($containerName, $current) ||
               str_contains($current, $containerName) ||
               str_contains($current, $managerPattern);
    }

    /**
     * Get sandbox container prefix from config
     *
     * @return string
     */
    private function getSandboxPrefix(): string
    {
        return $this->config->get('sandbox.sandbox.prefix', 'depthnet-sandbox');
    }

    /**
     * Get manager container pattern from config
     *
     * @return string
     */
    private function getManagerPattern(): string
    {
        return $this->config->get('sandbox.manager.container_pattern', 'sandbox-manager');
    }
}

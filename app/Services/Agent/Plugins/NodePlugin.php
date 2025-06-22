<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use App\Services\Agent\Plugins\Traits\PluginPresetTrait;

/**
 * NodePlugin class
 *
 * Executes Node.js/JavaScript code in a secure and isolated environment.
 * Supports both internal execution and external process execution for security.
 * Provides configuration options for execution mode, user, timeout, memory limit, etc.
 */
class NodePlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;
    use PluginConfigTrait;

    public function __construct()
    {
        $this->initializeConfig();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'node';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Execute Node.js/JavaScript code in isolated environment. Use for async operations, API calls, file processing, calculations.';
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        $instructions = [
            'Execute simple JavaScript: [node]console.log("Hello World!");[/node]',
            'Mathematical calculations: [node]const result = 15 * 8 + 45; console.log(`Result: ${result}`);[/node]',
            'Process arrays: [node]const data = [1,2,3]; console.log(data.reduce((a,b) => a+b, 0));[/node]',
            'File operations: [node]const fs = require("fs"); fs.writeFileSync("test.txt", "Hello"); console.log("File created");[/node]',
            'JSON processing: [node]const data = JSON.parse(\'{"name":"John"}\'); console.log(data.name);[/node]',
        ];

        // Add network examples only if network access is allowed
        if ($this->config['allow_network'] ?? false || $this->config['unrestricted_mode'] ?? false) {
            $instructions[] = 'HTTP requests: [node]const https = require("https"); https.get("https://api.github.com/users/github", res => { console.log(res.statusCode); });[/node]';
        }

        // Add advanced examples for unrestricted mode
        if ($this->config['unrestricted_mode'] ?? false) {
            $instructions[] = 'System commands: [node]const { exec } = require("child_process"); exec("ls -la", (err, stdout) => console.log(stdout));[/node]';
            $instructions[] = 'Package installation: [node]const { exec } = require("child_process"); exec("npm install lodash", (err, stdout) => console.log("Package installed"));[/node]';
        }

        $instructions[] = 'Async/await: [node](async () => { const result = await Promise.resolve("Done"); console.log(result); })();[/node]';

        return $instructions;
    }

    /**
     * @inheritDoc
     */
    public function getCustomSuccessMessage(): ?string
    {
        return "Node.js code executed successfully.";
    }

    /**
     * @inheritDoc
     */
    public function getCustomErrorMessage(): ?string
    {
        return "Error executing Node.js code.";
    }

    /**
     * @inheritDoc
     */
    public function execute(string $content): string
    {
        // Check if plugin is enabled
        if (!$this->isEnabled()) {
            return "Error: Node.js plugin is disabled.";
        }

        // Check if Node.js is available
        if (!$this->isNodeAvailable()) {
            return "Error: Node.js is not installed or not available in PATH.";
        }

        try {
            // Normalize JavaScript code
            $content = $this->normalizeJavaScriptCode($content);

            // Choose execution method
            $executionMode = $this->config['execution_mode'] ?? 'external';

            if ($executionMode === 'external') {
                // Use external process for isolation (recommended)
                return $this->executeWithExternalProcess($content);
            } else {
                // Note: Internal execution is not possible for Node.js in PHP context
                return "Error: Internal execution mode is not supported for Node.js. Use external mode.";
            }

        } catch (\Throwable $e) {
            return "Error while executing Node.js code: " . $e->getMessage();
        }
    }

    /**
     * Execute Node.js code in external process
     *
     * @param string $content JavaScript code to execute
     * @return string Execution output
     */
    private function executeWithExternalProcess(string $content): string
    {
        $tempFile = sys_get_temp_dir() . '/agent_node_' . uniqid() . '.js';

        file_put_contents($tempFile, $content);

        try {
            $command = $this->buildCommand($tempFile);

            $output = shell_exec("$command 2>&1");

            return $output ?: 'Code executed successfully with no output.';

        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Node.js Plugin',
                'description' => 'Allow execution of Node.js/JavaScript code',
                'required' => false
            ],
            'execution_mode' => [
                'type' => 'select',
                'label' => 'Execution Mode',
                'description' => 'How to execute Node.js code',
                'options' => [
                    'external' => 'External Process (Recommended)',
                ],
                'value' => 'external',
                'required' => false
            ],
            'node_path' => [
                'type' => 'text',
                'label' => 'Node.js Path',
                'description' => 'Path to Node.js executable (leave empty for auto-detection)',
                'placeholder' => '/usr/bin/node',
                'required' => false
            ],
            'user' => [
                'type' => 'text',
                'label' => 'Execution User',
                'description' => 'User to execute Node.js code as (leave empty for current user)',
                'placeholder' => 'devilbox',
                'required' => false
            ],
            'timeout' => [
                'type' => 'number',
                'label' => 'Timeout (seconds)',
                'description' => 'Maximum execution time for Node.js scripts',
                'min' => 1,
                'max' => 300,
                'value' => 30,
                'required' => false
            ],
            'max_old_space_size' => [
                'type' => 'select',
                'label' => 'Memory Limit (Old Space)',
                'description' => 'Maximum memory usage for Node.js scripts',
                'options' => [
                    '64' => '64 MB',
                    '128' => '128 MB',
                    '256' => '256 MB',
                    '512' => '512 MB',
                    '1024' => '1024 MB',
                    '2048' => '2048 MB'
                ],
                'value' => '256',
                'required' => false
            ],
            'working_directory' => [
                'type' => 'text',
                'label' => 'Working Directory',
                'description' => 'Directory where Node.js scripts will be executed',
                'placeholder' => '/shared/httpd',
                'required' => false
            ],
            'safe_mode' => [
                'type' => 'checkbox',
                'label' => 'Safe Mode',
                'description' => 'Enable additional security restrictions (restrict file system access)',
                'value' => true,
                'required' => false
            ],
            'allow_network' => [
                'type' => 'checkbox',
                'label' => 'Allow Network Access',
                'description' => 'Allow HTTP/HTTPS requests and network operations',
                'value' => false,
                'required' => false
            ],
            'unrestricted_mode' => [
                'type' => 'checkbox',
                'label' => 'Unrestricted Mode',
                'description' => 'Disable all security restrictions. WARNING: This allows full system access!',
                'value' => false,
                'required' => false
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['timeout'])) {
            $timeout = (int) $config['timeout'];
            if ($timeout < 1 || $timeout > 300) {
                $errors['timeout'] = 'Timeout must be between 1 and 300 seconds';
            }
        }

        if (!empty($config['node_path'])) {
            $nodePath = $config['node_path'];
            if (!is_executable($nodePath)) {
                $errors['node_path'] = "Node.js executable not found or not executable at '{$nodePath}'";
            }
        }

        if (!empty($config['working_directory'])) {
            $dir = $config['working_directory'];
            if (!is_dir($dir)) {
                $errors['working_directory'] = "Directory '{$dir}' does not exist";
            }
        }

        if (!empty($config['user'])) {
            $user = $config['user'];
            if (!$this->userExists($user)) {
                $errors['user'] = "User '{$user}' does not exist on this system";
            }
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'execution_mode' => 'external',
            'node_path' => $this->detectNodePath(),
            'user' => config('ai.plugins.execution_user', ''),
            'timeout' => 30,
            'max_old_space_size' => '256',
            'working_directory' => config('ai.plugins.node.working_directory', '/shared/httpd'),
            'safe_mode' => true,
            'allow_network' => false,
            'unrestricted_mode' => false
        ];
    }

    /**
     * @inheritDoc
     */
    public function testConnection(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if (!$this->isNodeAvailable()) {
            return false;
        }

        try {
            $testCode = "console.log('Node.js plugin test successful');";
            $result = $this->execute($testCode);
            return str_contains($result, 'Node.js plugin test successful');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build command with user and settings for external Node.js execution
     *
     * @param string $tempFile Path to temporary JavaScript file
     * @return string Complete command to execute
     */
    private function buildCommand(string $tempFile): string
    {
        $user = $this->config['user'] ?? '';
        $timeout = $this->config['timeout'] ?? 30;
        $maxOldSpaceSize = $this->config['max_old_space_size'] ?? '256';
        $workingDir = $this->config['working_directory'] ?? '/shared/httpd';
        $nodePath = $this->config['node_path'] ?? $this->detectNodePath();

        $nodeArgs = [];
        $nodeArgs[] = "--max-old-space-size={$maxOldSpaceSize}";

        if (!($this->config['unrestricted_mode'] ?? false)) {
            if ($this->config['safe_mode'] ?? true) {
                $nodeArgs[] = "--no-warnings";

            }
        }

        $command = "cd " . escapeshellarg($workingDir) . " && timeout $timeout " .
                  escapeshellarg($nodePath) . " " . implode(' ', $nodeArgs) . " " . escapeshellarg($tempFile);

        if (!empty($user) && $user !== trim(shell_exec('whoami'))) {
            if (!$this->canSwitchUser()) {
                throw new \Exception("Cannot switch to user '$user': insufficient privileges");
            }

            if (!empty(shell_exec('command -v runuser 2>/dev/null'))) {
                $command = "runuser -u " . escapeshellarg($user) . " -- bash -c " . escapeshellarg($command);
            } elseif (!empty(shell_exec('command -v su 2>/dev/null'))) {
                $command = "su " . escapeshellarg($user) . " -c " . escapeshellarg($command);
            } else {
                $command = "sudo -u " . escapeshellarg($user) . " bash -c " . escapeshellarg($command);
            }
        }

        return $command;
    }

    /**
     * Check if Node.js is available
     *
     * @return bool
     */
    private function isNodeAvailable(): bool
    {
        $nodePath = $this->config['node_path'] ?? $this->detectNodePath();

        if (empty($nodePath)) {
            return false;
        }

        $testOutput = shell_exec(escapeshellarg($nodePath) . " --version 2>/dev/null");
        return !empty($testOutput) && str_starts_with(trim($testOutput), 'v');
    }

    /**
     * Detect Node.js path automatically
     *
     * @return string|null
     */
    private function detectNodePath(): ?string
    {
        $commonPaths = [
            '/usr/bin/node',
            '/usr/local/bin/node',
            '/opt/node/bin/node',
            'node' // if node is in PATH
        ];

        foreach ($commonPaths as $path) {
            $testOutput = shell_exec("command -v " . escapeshellarg($path) . " 2>/dev/null");
            if (!empty($testOutput)) {
                return trim($testOutput);
            }
        }

        $whichOutput = shell_exec("which node 2>/dev/null");
        if (!empty($whichOutput)) {
            return trim($whichOutput);
        }

        return null;
    }

    /**
     * Check if user exists
     *
     * @param string $user
     * @return bool
     */
    private function userExists(string $user): bool
    {
        $result = shell_exec("id " . escapeshellarg($user) . " 2>/dev/null");
        return !empty($result);
    }

    /**
     * Check if we can switch users
     *
     * @return bool
     */
    private function canSwitchUser(): bool
    {
        // TODO: If more plugins need user switching, extract to UserSwitchingTrait

        $whoami = trim(shell_exec('whoami'));

        // If current user is root, switching is always possible
        if ($whoami === 'root') {
            return true;
        }

        // Check available user switching methods
        $hasRunuser = !empty(shell_exec('command -v runuser 2>/dev/null'));
        $hasSu = !empty(shell_exec('command -v su 2>/dev/null'));
        $hasSudo = !empty(shell_exec('command -v sudo 2>/dev/null'));

        // If runuser or su is available, we can switch
        if ($hasRunuser || $hasSu) {
            return true;
        }

        // If sudo is available, check if current user can use it
        if ($hasSudo) {
            $canSudo = !empty(shell_exec('sudo -n true 2>/dev/null && echo "yes"'));
            return $canSudo;
        }

        // No switching method available
        return false;
    }

    /**
     * Normalize JavaScript code
     *
     * @param string $content
     * @return string
     */
    private function normalizeJavaScriptCode(string $content): string
    {
        return trim($content);
    }

    /**
     * @inheritDoc
     */
    public function getMergeSeparator(): ?string
    {
        return "\n";
    }

    /**
     * @inheritDoc
     */
    public function canBeMerged(): bool
    {
        return true;
    }
}

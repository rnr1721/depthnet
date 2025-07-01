<?php

declare(strict_types=1);

namespace App\Services\Sandbox;

use App\Contracts\Sandbox\{
    ErrorHandlerFactoryInterface,
    SandboxServiceInterface,
    SandboxManagerInterface
};
use App\Exceptions\Sandbox\SandboxException;
use App\Services\Sandbox\DTO\CodeExecutionResult;
use App\Services\Sandbox\DTO\InstallationResult;
use App\Services\Sandbox\DTO\SandboxEnvironment;
use App\Services\Sandbox\Traits\SandboxLoggerTrait;
use Illuminate\Contracts\Config\Repository as Config;
use Psr\Log\LoggerInterface;

/**
 * High-level sandbox service implementation
 */
class SandboxService implements SandboxServiceInterface
{
    use SandboxLoggerTrait;

    private string $user = 'root';

    public function __construct(
        protected readonly SandboxManagerInterface $sandboxManager,
        protected readonly ErrorHandlerFactoryInterface $errorHandlerFactory,
        protected readonly Config $config,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function executeCode(string $code, string $language, array $options = []): CodeExecutionResult
    {
        $this->validateLanguage($language);

        $sandboxType = $options['sandbox_type'] ?? 'ubuntu-full';
        $timeout = $options['timeout'] ?? 30;

        $this->log('Executing code in new sandbox', [
            'language' => $language,
            'sandbox_type' => $sandboxType,
            'code_length' => strlen($code)
        ]);

        // Create temporary sandbox
        $sandbox = $this->sandboxManager->createSandbox($sandboxType);

        try {
            return $this->executeCodeInSandbox($sandbox->id, $code, $language, $options);
        } finally {
            // Cleanup temporary sandbox if auto_cleanup is enabled (default: true)
            if ($options['auto_cleanup'] ?? true) {
                $this->sandboxManager->destroySandbox($sandbox->id);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function executeCodeInSandbox(
        string $sandboxId,
        string $code,
        string $language,
        array $options = []
    ): CodeExecutionResult {
        $this->validateLanguage($language);

        $config = $this->config->get("sandbox.languages.{$language}");
        $timeout = $options['timeout'] ?? 30;
        $workDir = $options['work_dir'] ?? '/tmp';

        $this->log('Executing code in existing sandbox', [
            'sandbox_id' => $sandboxId,
            'language' => $language,
            'code_length' => strlen($code)
        ]);

        // Prepare
        $fileName = $options['filename'] ?? $this->generateFileName($language);
        $filePath = "{$workDir}/{$fileName}";

        // Write code to file
        $preparedCode = $this->prepareCode($code, $language);
        $escapedCode = base64_encode($preparedCode);
        $writeCommand = sprintf(
            'echo %s | base64 -d > %s',
            escapeshellarg($escapedCode),
            escapeshellarg($filePath)
        );

        $this->sandboxManager->executeCommand($sandboxId, $writeCommand, $this->user, 10);

        // Prepare execution command
        $executeCommand = $this->buildExecutionCommand($language, $filePath, $options);

        // Execute code
        $startTime = microtime(true);
        $result = $this->sandboxManager->executeCommand($sandboxId, $executeCommand, $this->user, $timeout);
        $executionTime = microtime(true) - $startTime;

        // Get output files if specified
        $outputFiles = [];
        if (!empty($options['output_files'])) {
            $outputFiles = $this->collectOutputFiles($sandboxId, $options['output_files']);
        }

        return new CodeExecutionResult(
            output: $result->output,
            error: $result->error,
            exitCode: $result->exitCode,
            executionTime: $executionTime,
            timedOut: $result->timedOut,
            language: $language,
            sandboxId: $sandboxId,
            files: $outputFiles,
            metadata: [
                'file_path' => $filePath,
                'work_dir' => $workDir,
                'interpreter' => $config['interpreter']
            ]
        );
    }

    /**
     * @inheritDoc
     */
    public function createPersistentSandbox(
        string $language,
        array $requirements = [],
        array $options = []
    ): string {
        $this->validateLanguage($language);

        $sandboxType = $options['sandbox_type'] ?? 'ubuntu-full';
        $name = $options['name'] ?? "persistent-{$language}-" . uniqid();

        $this->log('Creating persistent sandbox', [
            'language' => $language,
            'name' => $name,
            'requirements' => $requirements
        ]);

        $sandbox = $this->sandboxManager->createSandbox($sandboxType, $name);

        // Install requirements if specified
        if (!empty($requirements)) {
            $this->installPackages($sandbox->id, $requirements, $language);
        }

        // Setup working directory
        $workDir = $options['work_dir'] ?? '/tmp/workspace';
        $this->sandboxManager->executeCommand(
            $sandbox->id,
            "mkdir -p {$workDir} && cd {$workDir}",
            $this->user,
            10
        );

        return $sandbox->id;
    }

    /**
     * @inheritDoc
     */
    public function installPackages(
        string $sandboxId,
        array $packages,
        string $language
    ): InstallationResult {
        $this->validateLanguage($language);

        $config = $this->config->get("sandbox.languages.{$language}");
        $installCommand = $config['install_command'];

        $this->log('Installing packages', [
            'sandbox_id' => $sandboxId,
            'language' => $language,
            'packages' => $packages
        ]);

        $installedPackages = [];
        $failedPackages = [];
        $allOutput = '';
        $allErrors = '';

        foreach ($packages as $package) {
            $command = "{$installCommand} " . escapeshellarg($package);

            try {
                $result = $this->sandboxManager->executeCommand($sandboxId, $command, $this->user, 120);

                $allOutput .= "Installing {$package}:\n{$result->output}\n\n";

                if ($result->exitCode === 0) {
                    $installedPackages[] = $package;
                } else {
                    $failedPackages[] = $package;
                    $allErrors .= "Failed to install {$package}: {$result->error}\n";
                }
            } catch (SandboxException $e) {
                $failedPackages[] = $package;
                $allErrors .= "Exception installing {$package}: {$e->getMessage()}\n";
            }
        }

        return new InstallationResult(
            success: empty($failedPackages),
            installedPackages: $installedPackages,
            failedPackages: $failedPackages,
            output: trim($allOutput),
            error: trim($allErrors)
        );
    }

    /**
     * @inheritDoc
     */
    public function getSandboxEnvironment(string $sandboxId): SandboxEnvironment
    {
        $this->log('Getting sandbox environment', ['sandbox_id' => $sandboxId], 'debug');

        // Get language versions
        $languages = [];
        $supportedLanguages = $this->config->get('sandbox.languages');

        foreach ($supportedLanguages as $lang => $config) {
            try {
                $result = $this->sandboxManager->executeCommand(
                    $sandboxId,
                    $config['version_command'],
                    'root',
                    10
                );

                if ($result->exitCode === 0) {
                    $languages[$lang] = trim($result->output);
                }
            } catch (SandboxException $e) {
                // Language not available
            }
        }

        // Get installed Python packages
        $installedPackages = [];
        try {
            $result = $this->sandboxManager->executeCommand($sandboxId, 'pip3 list --format=freeze', 'root', 10);
            if ($result->exitCode === 0) {
                $installedPackages['python'] = $this->parsePipList($result->output);
            }
        } catch (SandboxException $e) {
            // Ignore
        }

        // Get Node.js packages
        try {
            $result = $this->sandboxManager->executeCommand($sandboxId, 'npm list -g --depth=0', 'root', 10);
            if ($result->exitCode === 0) {
                $installedPackages['javascript'] = $this->parseNpmList($result->output);
            }
        } catch (SandboxException $e) {
            // Ignore
        }

        // Get system info
        $systemInfo = [];
        try {
            $result = $this->sandboxManager->executeCommand($sandboxId, 'uname -a', 'root', 5);
            if ($result->exitCode === 0) {
                $systemInfo['os'] = trim($result->output);
            }

            $result = $this->sandboxManager->executeCommand($sandboxId, 'cat /etc/os-release | grep PRETTY_NAME', 'root', 5);
            if ($result->exitCode === 0) {
                $systemInfo['distribution'] = trim(str_replace('PRETTY_NAME=', '', $result->output), '"');
            }
        } catch (SandboxException $e) {
            // Ignore
        }

        return new SandboxEnvironment(
            sandboxId: $sandboxId,
            languages: $languages,
            installedPackages: $installedPackages,
            systemInfo: $systemInfo
        );
    }

    /**
     * @inheritDoc
     */
    public function uploadFile(string $sandboxId, string $filePath, string $content): bool
    {
        $this->log('Uploading file to sandbox', [
            'sandbox_id' => $sandboxId,
            'file_path' => $filePath,
            'content_size' => strlen($content)
        ], 'debug');

        $escapedContent = base64_encode($content);
        $command = sprintf(
            'mkdir -p %s && echo %s | base64 -d > %s',
            escapeshellarg(dirname($filePath)),
            escapeshellarg($escapedContent),
            escapeshellarg($filePath)
        );

        $result = $this->sandboxManager->executeCommand($sandboxId, $command, $this->user, 30);
        return $result->exitCode === 0;
    }

    /**
     * @inheritDoc
     */
    public function downloadFile(string $sandboxId, string $filePath): string
    {
        $this->log('Downloading file from sandbox', [
            'sandbox_id' => $sandboxId,
            'file_path' => $filePath
        ], 'debug');

        $command = sprintf('cat %s', escapeshellarg($filePath));
        $result = $this->sandboxManager->executeCommand($sandboxId, $command, $this->user, 30);

        if ($result->exitCode !== 0) {
            throw new SandboxException("Failed to read file: {$filePath}");
        }

        return $result->output;
    }

    /**
     * @inheritDoc
     */
    public function getSupportedLanguages(): array
    {
        return $this->config->get('sandbox.languages');
    }

    /**
     * @inheritDoc
     */
    public function setUser(string $user): self
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Validate that language is supported
     *
     * @param string $language
     * @return void
     */
    private function validateLanguage(string $language): void
    {
        $supportedLanguages = $this->config->get('sandbox.languages');
        if (!isset($supportedLanguages[$language])) {
            throw new SandboxException("Unsupported language: {$language}");
        }
    }

    /**
     * Generate filename for code execution
     *
     * @param string $language
     * @return string
     */
    private function generateFileName(string $language): string
    {
        $languageConfig = $this->config->get("sandbox.languages.{$language}");
        $extension = $languageConfig['extension'] ?? 'txt';
        return 'code_' . uniqid() . '.' . $extension;
    }

    /**
     * Build execution command for language
     *
     * @param string $language
     * @param string $filePath
     * @param array $options
     * @return string
     */
    private function buildExecutionCommand(string $language, string $filePath, array $options): string
    {
        $languageConfig = $this->config->get("sandbox.languages.{$language}");
        $interpreter = $languageConfig['interpreter'] ?? $language;

        $handler = $this->errorHandlerFactory->create($language);

        if ($handler && method_exists($handler, 'buildExecutionCommand')) {
            return $handler->buildExecutionCommand($interpreter, $filePath);
        }

        // Fallback to default execution
        return "{$interpreter} {$filePath}";
    }

    /**
     * Collect output files from sandbox
     *
     * @param string $sandboxId
     * @param array $filePaths
     * @return array
     */
    private function collectOutputFiles(string $sandboxId, array $filePaths): array
    {
        $files = [];

        foreach ($filePaths as $filePath) {
            try {
                $content = $this->downloadFile($sandboxId, $filePath);
                $files[$filePath] = $content;
            } catch (SandboxException $e) {
                $this->log('Failed to collect output file', [
                    'sandbox_id' => $sandboxId,
                    'file_path' => $filePath,
                    'error' => $e->getMessage()
                ], 'warning');
            }
        }

        return $files;
    }

    /**
     * Parse pip list output in freeze format
     *
     * @param string $output
     * @return array
     */
    private function parsePipList(string $output): array
    {
        $packages = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '==')) {
                [$name, $version] = explode('==', $line, 2);
                $packages[trim($name)] = trim($version);
            }
        }

        return $packages;
    }

    /**
     * Parse npm list output
     *
     * @param string $output
     * @return array
     */
    private function parseNpmList(string $output): array
    {
        $packages = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_contains($line, 'npm list') || str_contains($line, '├──') || str_contains($line, '└──')) {
                continue;
            }

            // Parse lines like "package@version"
            if (preg_match('/([^@]+)@(.+)/', $line, $matches)) {
                $packages[trim($matches[1])] = trim($matches[2]);
            }
        }

        return $packages;
    }

    /**
     * Prepare code for execution (add language-specific wrappers)
     *
     * @param string $code
     * @param string $language
     * @return string
     */
    private function prepareCode(string $code, string $language): string
    {
        $handler = $this->errorHandlerFactory->create($language);

        if ($handler && method_exists($handler, 'prepareCode')) {
            return $handler->prepareCode($code);
        }

        // Fallback to original code if no handler available
        return $code;
    }

}

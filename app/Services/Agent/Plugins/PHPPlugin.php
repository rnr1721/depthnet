<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use App\Services\Agent\Plugins\Traits\PluginPresetTrait;

/**
 * PHPPlugin class
 *
 * Executes PHP code in a secure and isolated environment.
 * Supports both eval() for simple scripts and external process execution for security.
 * Provides configuration options for execution mode, user, timeout, memory limit, etc.
 */
class PHPPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct()
    {
        $this->initializeConfig();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'php';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Execute PHP code in isolated environment. Use for calculations, data processing, standalone scripts.';
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        return [
            'Execute simple PHP code: [php]echo "Hello World!";[/php]',
            'Mathematical calculations: [php]$result = 15 * 8 + 45; echo "Result: $result";[/php]',
            'Process data: [php]$data = [1,2,3]; echo array_sum($data);[/php]',
            'File operations: [php]file_put_contents("test.txt", "Hello"); echo "File created";[/php]',
            'JSON processing: [php]$json = \'{"name":"John"}\'; $data = json_decode($json, true); echo $data["name"];[/php]',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getCustomSuccessMessage(): ?string
    {
        return "PHP code executed successfully.";
    }

    /**
     * @inheritDoc
     */
    public function getCustomErrorMessage(): ?string
    {
        return "Error executing PHP code.";
    }

    /**
     * @inheritDoc
     */
    public function execute(string $content): string
    {
        // Check if plugin is enabled
        if (!$this->isEnabled()) {
            return "Error: PHP plugin is disabled.";
        }

        try {
            // Normalize PHP code
            $content = $this->normalizePhpCode($content);

            // Choose execution method
            $executionMode = $this->config['execution_mode'] ?? 'external';

            if ($executionMode === 'eval') {
                // Use eval for simple scripts (as in original)
                return $this->executeWithEval($content);
            } else {
                // Use external process for isolation
                return $this->executeWithExternalProcess($content);
            }

        } catch (\Throwable $e) {
            return "Error while executing PHP code: " . $e->getMessage();
        }
    }

    /**
     * Execute PHP code in external process (safer)
     *
     * @param string $content
     * @return string
     */
    private function executeWithExternalProcess(string $content): string
    {
        // Create temporary file
        $tempFile = sys_get_temp_dir() . '/agent_php_' . uniqid() . '.php';

        // Write code to file
        $finalCode = $this->startsWithPhpTag($content) ? $content : "<?php\n" . $content;
        file_put_contents($tempFile, $finalCode);

        // Build command with settings
        $command = $this->buildCommand($tempFile);

        // Execute in isolated process
        $output = shell_exec("$command 2>&1");

        // Remove temporary file
        unlink($tempFile);

        return $output ?: 'Code executed successfully with no output.';
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable PHP Plugin',
                'description' => 'Allow execution of PHP code',
                'required' => false
            ],
            'execution_mode' => [
                'type' => 'select',
                'label' => 'Execution Mode',
                'description' => 'How to execute PHP code',
                'options' => [
                    'external' => 'External Process (Safer)',
                    'eval' => 'Internal eval() (Faster)'
                ],
                'value' => 'external',
                'required' => false
            ],
            'user' => [
                'type' => 'text',
                'label' => 'Execution User',
                'description' => 'User to execute PHP code as (leave empty for current user)',
                'placeholder' => 'devilbox',
                'required' => false
            ],
            'timeout' => [
                'type' => 'number',
                'label' => 'Timeout (seconds)',
                'description' => 'Maximum execution time for PHP scripts',
                'min' => 1,
                'max' => 300,
                'value' => 30,
                'required' => false
            ],
            'memory_limit' => [
                'type' => 'select',
                'label' => 'Memory Limit',
                'description' => 'Maximum memory usage for PHP scripts',
                'options' => [
                    '32M' => '32 MB',
                    '64M' => '64 MB',
                    '128M' => '128 MB',
                    '256M' => '256 MB',
                    '512M' => '512 MB'
                ],
                'value' => '128M',
                'required' => false
            ],
            'max_execution_time' => [
                'type' => 'number',
                'label' => 'Max Execution Time',
                'description' => 'PHP max_execution_time setting',
                'min' => 1,
                'max' => 300,
                'value' => 30,
                'required' => false
            ],
            'safe_mode' => [
                'type' => 'checkbox',
                'label' => 'Safe Mode',
                'description' => 'Enable additional security restrictions (disable dangerous functions)',
                'value' => true,
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

        // Validate timeout
        if (isset($config['timeout'])) {
            $timeout = (int) $config['timeout'];
            if ($timeout < 1 || $timeout > 300) {
                $errors['timeout'] = 'Timeout must be between 1 and 300 seconds';
            }
        }

        // Validate max_execution_time
        if (isset($config['max_execution_time'])) {
            $maxTime = (int) $config['max_execution_time'];
            if ($maxTime < 1 || $maxTime > 300) {
                $errors['max_execution_time'] = 'Max execution time must be between 1 and 300 seconds';
            }
        }

        // Validate user exists if specified
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
            'execution_mode' => 'external', // external for security, eval for compatibility
            'user' => config('ai.plugins.execution_user', ''),
            'timeout' => 30,
            'memory_limit' => '256M',
            'max_execution_time' => 30,
            'safe_mode' => true
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

        try {
            $testCode = "echo 'PHP plugin test successful';";
            $result = $this->execute($testCode);
            return str_contains($result, 'PHP plugin test successful');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build command with user and settings for external PHP execution
     *
     * @param string $tempFile Path to temporary PHP file
     * @return string Complete command to execute
     */
    private function buildCommand(string $tempFile): string
    {
        $user = $this->config['user'] ?? '';
        $timeout = $this->config['timeout'] ?? 30;
        $memoryLimit = $this->config['memory_limit'] ?? '128M';
        $maxExecutionTime = $this->config['max_execution_time'] ?? 30;

        // Build PHP settings
        $phpSettings = [];
        $phpSettings[] = "-d memory_limit={$memoryLimit}";
        $phpSettings[] = "-d max_execution_time={$maxExecutionTime}";

        if ($this->config['safe_mode'] ?? true) {
            $phpSettings[] = "-d disable_functions=exec,shell_exec,system,passthru,proc_open";
        }

        // Build base command
        $command = "timeout $timeout php " . implode(' ', $phpSettings) . " -f " . escapeshellarg($tempFile);

        // Add user switching if specified and different from current
        if (!empty($user) && $user !== trim(shell_exec('whoami'))) {
            if (!$this->canSwitchUser()) {
                throw new \Exception("Cannot switch to user '$user': insufficient privileges");
            }

            // Choose optimal switching method (same as ShellPlugin)
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
     * Execute PHP code using eval
     *
     * @param string $content
     * @return string
     */
    private function executeWithEval(string $content): string
    {
        try {
            // Set memory limit
            if (isset($this->config['memory_limit'])) {
                ini_set('memory_limit', $this->config['memory_limit']);
            }

            // Set execution time limit
            if (isset($this->config['max_execution_time'])) {
                set_time_limit($this->config['max_execution_time']);
            }

            ob_start();
            eval($content);
            $result = ob_get_clean();

            return $result ?: 'Code executed successfully with no output.';
        } catch (\Throwable $e) {
            return "Error while executing PHP code: " . $e->getMessage();
        }
    }

    /**
     * Check if user exists
     *
     * @param string $user
     * @return boolean
     */
    private function userExists(string $user): bool
    {
        $result = shell_exec("id " . escapeshellarg($user) . " 2>/dev/null");
        return !empty($result);
    }

    /**
     * Check if we can switch users
     *
     * @return boolean
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
     * Normalize PHP code
     *
     * @param string $content
     * @return string
     */
    private function normalizePhpCode(string $content): string
    {
        if (strpos($content, '?>') !== false) {
            $content = substr($content, 0, strpos($content, '?>'));
        }
        return trim($content);
    }

    /**
     * Check if code starts with PHP tag
     *
     * @param string $content
     * @return boolean
     */
    private function startsWithPhpTag(string $content): bool
    {
        return preg_match('/^\s*<\?(php)?/i', $content);
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

    /**
     * @inheritDoc
     */
    public function pluginReady(): void
    {
        // Nothing to do here
    }

}

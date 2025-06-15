<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;

/**
 * ShellPlugin class
 *
 * Provides a way to execute shell commands with safety checks and user switching.
 * Allows interaction with the Linux operating system through a command line interface.
 */
class ShellPlugin implements CommandPluginInterface
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
        return 'shell';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Execute shell commands and interact with the linux operating system. Provides safe access to common system operations and utilities.';
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        return [
            'Execute any shell command: [shell]command here[/shell]',
            'List files: [shell]ls -la[/shell]',
            'View file: [shell]cat filename.txt[/shell]',
            'Current directory: [shell]pwd[/shell]',
            'System info: [shell]df -h && free -h[/shell]',
            'Find processes: [shell]ps aux | grep nginx[/shell]',
            'Create folder: [shell]mkdir new_folder[/shell]',
            'Find files: [shell]find . -name "*.php"[/shell]',
            'Test network: [shell]ping -c 3 google.com[/shell]'
        ];
    }

    /**
     * @inheritDoc
     */
    public function getCustomSuccessMessage(): ?string
    {
        return "Shell command executed successfully.";
    }

    /**
     * @inheritDoc
     */
    public function getCustomErrorMessage(): ?string
    {
        return "Error executing shell command.";
    }

    /**
     * @inheritDoc
     */
    public function execute(string $content): string
    {
        $shellPrompt = $this->buildPrompt();
        if (str_starts_with($content, $shellPrompt) && !empty($shellPrompt)) {
            // Remove shell prompt if it exists
            $content = substr($content, strlen($shellPrompt));
        }

        if (!$this->isEnabled()) {
            return "Error: Shell plugin is disabled.";
        }

        try {
            // Security is our hell
            if ($this->config['security_enabled'] ?? true && $this->isDangerousCommand($content)) {
                return "Error: Dangerous command blocked for security reasons.";
            }

            $command = $this->buildCommand($content);

            // Run the command with timeout and capture output
            $output = [];
            $returnCode = 0;
            exec("$command 2>&1", $output, $returnCode);

            $result = implode("\n", $output);

            // Extract current directory and save it
            if (preg_match('/<<<CURRENT_DIR>>>(.+?)$/m', $result, $matches)) {
                $currentDir = trim($matches[1]);
                $this->saveCurrentDirectory($currentDir);
                $result = preg_replace('/<<<CURRENT_DIR>>>.+$/m', '', $result);
                $result = rtrim($result);
            } else {
                $currentDir = $this->getCurrentWorkingDirectory();
            }

            if ($returnCode !== 0) {
                return "Command failed with exit code {$returnCode}:\n{$result}";
            }

            return $this->buildPrompt() . $content . "\n" . $result ?: 'Command executed successfully with no output.';

        } catch (\Throwable $e) {
            return "Error while executing shell command: " . $e->getMessage();
        }
    }

    /**
     * Get current working directory for command execution
     *
     * @return string
     */
    private function getCurrentWorkingDirectory(): string
    {
        // If we have a saved current directory from previous commands, use it
        if (!empty($this->config['current_directory'])) {
            return $this->config['current_directory'];
        }

        // Otherwise use configured working directory or default
        return $this->config['working_directory'] ?? getcwd();
    }

    /**
     * Save current directory state
     *
     * @param string $directory
     */
    private function saveCurrentDirectory(string $directory): void
    {
        $this->config['current_directory'] = $directory;
    }

    /**
     * Reset current directory to working directory
     */
    public function resetCurrentDirectory(): void
    {
        $this->config['current_directory'] = null;
    }

    /**
     * Build shell-like prompt
     *
     * @return string
     */
    private function buildPrompt(): string
    {
        if (!($this->config['show_shell_prompt'] ?? false)) {
            return '';
        }
        $realcwd = $this->getCurrentWorkingDirectory();
        $user = $this->config['user'] ?: trim(shell_exec('whoami'));
        $host = trim(shell_exec('hostname'));

        $symbol = ($user === 'root') ? '#' : '$';

        return "{$user}@{$host}:{$realcwd} {$symbol} ";
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Shell Plugin',
                'description' => 'Allow execution of shell commands',
                'required' => false
            ],
            'user' => [
                'type' => 'text',
                'label' => 'Execution User',
                'description' => 'User to execute commands as (leave empty for current user)',
                'placeholder' => 'devilbox',
                'required' => false
            ],
            'show_shell_prompt' => [
                'type' => 'checkbox',
                'label' => 'Show Shell Prompt',
                'description' => 'Display shell-like prompt (user@host:path $)',
                'required' => false
            ],
            'working_directory' => [
                'type' => 'text',
                'label' => 'Working Directory',
                'description' => 'Default directory for command execution',
                'placeholder' => '/shared/httpd',
                'required' => false
            ],
            'timeout' => [
                'type' => 'number',
                'label' => 'Timeout (seconds)',
                'description' => 'Maximum execution time for commands',
                'min' => 1,
                'max' => 600,
                'value' => 60,
                'required' => false
            ],
            'security_enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Security Checks',
                'description' => 'Block dangerous commands',
                'value' => true,
                'required' => false
            ],
            'allowed_directories' => [
                'type' => 'textarea',
                'label' => 'Allowed Directories',
                'description' => 'List of directories where commands can be executed (one per line)',
                'placeholder' => "/shared/httpd\n/tmp\n/var/tmp",
                'rows' => 4,
                'required' => false
            ],
            'dangerous_commands' => [
                'type' => 'textarea',
                'label' => 'Dangerous Commands',
                'description' => 'Additional commands to block (one per line)',
                'placeholder' => "custom_dangerous_command\nanother_blocked_command",
                'rows' => 6,
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
            if ($timeout < 1 || $timeout > 600) {
                $errors['timeout'] = 'Timeout must be between 1 and 600 seconds';
            }
        }

        // Validate working directory exists
        if (!empty($config['working_directory'])) {
            $dir = $config['working_directory'];
            if (!is_dir($dir)) {
                $errors['working_directory'] = "Directory '{$dir}' does not exist";
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
            'user' => config('ai.plugins.execution_user', ''),
            'show_shell_prompt' => true,
            'working_directory' => config('ai.plugins.shell.working_directory', '/shared/httpd'),
            'current_directory' => null,
            'timeout' => 60,
            'security_enabled' => true,
            'allowed_directories' => [
                '/shared/httpd',
                '/tmp',
                '/var/tmp'
            ],
            'dangerous_commands' => []
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
            $result = $this->execute('echo "Shell plugin test successful"');
            return str_contains($result, 'Shell plugin test successful');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build command with user and settings
     *
     * @param string $baseCommand
     * @return string
     */
    private function buildCommand(string $baseCommand): string
    {
        $user = $this->config['user'] ?? '';
        $currentDir = $this->getCurrentWorkingDirectory();
        $timeout = $this->config['timeout'] ?? 60;

        // Build combined command with directory tracking inside the same shell session
        $combinedCommand = $baseCommand . '; echo "<<<CURRENT_DIR>>>$(pwd)"';
        $escapedCombinedCommand = escapeshellarg($combinedCommand);
        $escapedCurrentDir = escapeshellarg($currentDir);

        // Build the command with proper escaping
        $command = "cd {$escapedCurrentDir} && timeout {$timeout} bash -c {$escapedCombinedCommand}";

        // If user is specified and it's not the current user
        if (!empty($user) && $user !== trim(shell_exec('whoami'))) {
            if (!$this->canSwitchUser()) {
                throw new \Exception("Cannot switch to user '$user': insufficient privileges");
            }

            // Choose the best method to switch users
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
     * Check if command is dangerous
     *
     * @param string $command
     * @return boolean
     */
    protected function isDangerousCommand(string $command): bool
    {
        $defaultDangerous = [
            'rm -rf /', 'sudo', 'su ', 'passwd', 'chmod 777', 'chown',
            'shutdown', 'reboot', 'halt', 'init', 'killall', 'pkill',
            'kill -9', 'dd if=', 'format', 'fdisk', 'mount', 'umount',
            'crontab', 'systemctl', 'service', ':(){:|:&};:', 'wget',
            'curl', 'nc ', 'netcat'
        ];

        $customDangerous = $this->config['dangerous_commands'] ?? [];
        if (is_string($customDangerous)) {
            $customDangerous = array_filter(array_map('trim', explode("\n", $customDangerous)));
        }

        $allDangerous = array_merge($defaultDangerous, $customDangerous);
        $command = strtolower(trim($command));

        foreach ($allDangerous as $dangerous) {
            if (strpos($command, strtolower($dangerous)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user exists
     *
     * @param string $user
     * @return boolean
     */
    private function userExists(string $user): bool
    {
        $result = shell_exec("id $user 2>/dev/null");
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

        if ($whoami === 'root') {
            return true;
        }

        // Check for runuser, su, sudo, any of these means we can switch users
        $hasRunuser = !empty(shell_exec('command -v runuser 2>/dev/null'));
        $hasSu = !empty(shell_exec('command -v su 2>/dev/null'));
        $hasSudo = !empty(shell_exec('command -v sudo 2>/dev/null'));

        // If we have runuser or su, we can switch users
        if ($hasRunuser || $hasSu) {
            return true;
        }

        // If we have sudo, check if the user can use it without password
        if ($hasSudo) {
            $canSudo = !empty(shell_exec('sudo -n true 2>/dev/null && echo "yes"'));
            return $canSudo;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function getMergeSeparator(): ?string
    {
        return " && ";
    }

    /**
     * @inheritDoc
     */
    public function canBeMerged(): bool
    {
        return true;
    }
}

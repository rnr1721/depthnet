<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;

/**
 * ShellPlugin — stateless.
 *
 * ⚠️  HOST EXECUTION PLUGIN ⚠️
 *
 * Unlike SandboxPlugin (which executes inside an isolated Docker container),
 * this plugin runs commands DIRECTLY ON THE HOST as the PHP process user.
 * That means an enabled ShellPlugin can read your .env, modify files, install
 * packages, exfiltrate secrets — anything the PHP user can do.
 *
 * It is preserved primarily for operational/admin tasks (restart a service,
 * check disk usage, look at logs) on installations that knowingly choose
 * this trade-off. For agent-driven code execution, prefer SandboxPlugin.
 *
 * Per-preset state:
 *   - current_directory is no longer stored in $this->config (the singleton
 *     is shared across presets — that was a bug). It now lives in
 *     PluginMetadataService keyed by preset, surviving cd commands within
 *     a session and across requests.
 */
class ShellPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'shell';

    protected bool $isTesting = false;

    protected array $defaultDangerous = [
        'rm -rf /', 'sudo', 'su ', 'passwd', 'chmod 777', 'chown',
        'shutdown', 'reboot', 'halt', 'init', 'killall', 'pkill',
        'kill -9', 'dd if=', 'format', 'fdisk', 'mount', 'umount',
        'crontab', 'systemctl', 'service', ':(){:|:&};:', 'wget',
        'curl', 'nc ', 'netcat'
    ];

    public function __construct(
        protected PluginMetadataServiceInterface $pluginMetadata
    ) {
    }

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(array $config = []): string
    {
        return '⚠️ HOST EXECUTION — runs commands DIRECTLY on the host as the PHP process user. '
             . 'Not isolated. Can read/modify any file the web user can. '
             . 'Use SandboxPlugin for code execution; reserve this for trusted operational tasks.';
    }

    public function getInstructions(array $config = []): array
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
            'Test network: [shell]ping -c 3 google.com[/shell]',
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return "Shell command executed successfully.";
    }

    public function getCustomErrorMessage(): ?string
    {
        return "Error executing shell command.";
    }

    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Shell plugin is disabled.";
        }

        $shellPrompt = $this->buildPrompt($context);
        if (!empty($shellPrompt) && str_starts_with($content, $shellPrompt)) {
            // Remove shell prompt if it exists
            $content = substr($content, strlen($shellPrompt));
        }

        try {
            // Security check — skipped only when running unit tests with $isTesting=true
            if (!$this->isTesting) {
                if ($context->get('security_enabled', true) && $this->isDangerousCommand($context, $content)) {
                    return "Error: Dangerous command blocked for security reasons.";
                }
            }

            $command = $this->buildCommand($context, $content);

            $output = [];
            $returnCode = 0;
            exec("$command 2>&1", $output, $returnCode);

            $result = implode("\n", $output);

            // Extract current directory and persist it via metadata
            if (preg_match('/<<<CURRENT_DIR>>>(.+?)$/m', $result, $matches)) {
                $newDir = trim($matches[1]);
                $this->saveCurrentDirectory($context->preset, $newDir);
                $result = preg_replace('/<<<CURRENT_DIR>>>.+$/m', '', $result);
                $result = rtrim($result);
            }

            if ($returnCode !== 0) {
                return "Command failed with exit code {$returnCode}:\n{$result}";
            }

            return $this->buildPrompt($context) . $content . "\n" . ($result ?: 'Command executed successfully with no output.');

        } catch (\Throwable $e) {
            return "Error while executing shell command: " . $e->getMessage();
        }
    }

    /**
     * Reset current directory for a preset back to the configured default.
     * Called from outside (e.g. cleanup commands), so it accepts AiPreset
     * directly rather than a context.
     */
    public function resetCurrentDirectory(AiPreset $preset): void
    {
        $this->pluginMetadata->set($preset, self::PLUGIN_NAME, 'current_directory', null);
    }

    public function getConfigFields(): array
    {
        $dangerousCommands = '';
        foreach ($this->defaultDangerous as $dangerous) {
            $dangerousCommands .= $dangerous . "\n";
        }

        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => '⚠️ Enable Shell Plugin (HOST EXECUTION)',
                'description' => 'WARNING: This plugin executes commands directly on the host system. Only enable if you understand the security implications and trust the agent prompts. For code execution use SandboxPlugin instead.',
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
                'description' => 'Block dangerous commands listed below',
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
                'description' => 'Custom commands to block (one per line, override defaults)',
                'placeholder' => $dangerousCommands,
                'rows' => 6,
                'required' => false
            ]
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['timeout'])) {
            $timeout = (int) $config['timeout'];
            if ($timeout < 1 || $timeout > 600) {
                $errors['timeout'] = 'Timeout must be between 1 and 600 seconds';
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
     * disabled by default. Admin must consciously enable host
     * execution after reading the warning in the UI description.
     */
    public function getDefaultConfig(): array
    {
        return [
            'enabled' => false,
            'user' => config('ai.plugins.execution_user', ''),
            'show_shell_prompt' => true,
            'working_directory' => config('ai.plugins.shell.working_directory', '/shared/httpd'),
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

    public function getMergeSeparator(): ?string
    {
        return " && ";
    }

    public function canBeMerged(): bool
    {
        return true;
    }

    public function getSelfClosingTags(): array
    {
        return [];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Get the current working directory for a preset.
     * Reads from PluginMetadataService (preserves cd between commands)
     * with fallback to the preset's configured working_directory.
     */
    private function getCurrentWorkingDirectory(PluginExecutionContext $context): string
    {
        $stored = $this->pluginMetadata->get($context->preset, self::PLUGIN_NAME, 'current_directory');

        if (!empty($stored)) {
            return $stored;
        }

        return $context->get('working_directory', getcwd());
    }

    private function saveCurrentDirectory(AiPreset $preset, string $directory): void
    {
        $this->pluginMetadata->set($preset, self::PLUGIN_NAME, 'current_directory', $directory);
    }

    private function buildPrompt(PluginExecutionContext $context): string
    {
        if (!$context->get('show_shell_prompt', false)) {
            return '';
        }

        $realcwd = $this->getCurrentWorkingDirectory($context);
        $user = $context->get('user', '') ?: trim(shell_exec('whoami'));
        $host = trim(shell_exec('hostname'));

        $symbol = ($user === 'root') ? '#' : '$';

        return "{$user}@{$host}:{$realcwd} {$symbol} ";
    }

    private function buildCommand(PluginExecutionContext $context, string $baseCommand): string
    {
        $user = $context->get('user', '');
        $currentDir = $this->getCurrentWorkingDirectory($context);
        $timeout = (int) $context->get('timeout', 60);

        // Build combined command with directory tracking inside the same shell session
        $combinedCommand = $baseCommand . '; echo "<<<CURRENT_DIR>>>$(pwd)"';
        $escapedCombinedCommand = escapeshellarg($combinedCommand);
        $escapedCurrentDir = escapeshellarg($currentDir);

        $command = "cd {$escapedCurrentDir} && timeout {$timeout} bash -c {$escapedCombinedCommand}";

        // If user is specified and it's not the current user
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
     * Check if a command matches the dangerous-commands blacklist.
     * Reads custom blacklist from context (per-preset) and falls back
     * to defaults if the admin didn't customise it.
     */
    protected function isDangerousCommand(PluginExecutionContext $context, string $command): bool
    {
        $customDangerous = $context->get('dangerous_commands', []);
        if (is_string($customDangerous)) {
            $customDangerous = array_filter(array_map('trim', explode("\n", $customDangerous)));
        }

        $allDangerous = empty($customDangerous) ? $this->defaultDangerous : $customDangerous;
        $command = strtolower(trim($command));

        foreach ($allDangerous as $dangerous) {
            if (strpos($command, strtolower($dangerous)) !== false) {
                return true;
            }
        }

        return false;
    }

    private function userExists(string $user): bool
    {
        $result = shell_exec("id $user 2>/dev/null");
        return !empty($result);
    }

    private function canSwitchUser(): bool
    {
        $whoami = trim(shell_exec('whoami'));

        if ($whoami === 'root') {
            return true;
        }

        $hasRunuser = !empty(shell_exec('command -v runuser 2>/dev/null'));
        $hasSu = !empty(shell_exec('command -v su 2>/dev/null'));
        $hasSudo = !empty(shell_exec('command -v sudo 2>/dev/null'));

        if ($hasRunuser || $hasSu) {
            return true;
        }

        if ($hasSudo) {
            return !empty(shell_exec('sudo -n true 2>/dev/null && echo "yes"'));
        }

        return false;
    }
}

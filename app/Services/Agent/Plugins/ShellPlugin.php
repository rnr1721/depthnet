<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;

class ShellPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;

    public function getName(): string
    {
        return 'shell';
    }

    public function execute(string $content): string
    {
        try {
            // Basic Security - Blocking Dangerous Commands
            if ($this->isDangerousCommand($content)) {
                return "Error: Dangerous command blocked for security reasons.";
            }

            // We execute the command and capture the output
            $output = [];
            $returnCode = 0;

            exec($content . ' 2>&1', $output, $returnCode);

            $result = implode("\n", $output);

            if ($returnCode !== 0) {
                return "Command failed with exit code {$returnCode}:\n{$result}";
            }

            return $result ?: 'Command executed successfully with no output.';

        } catch (\Throwable $e) {
            return "Error while executing shell command: " . $e->getMessage();
        }
    }

    protected function isDangerousCommand(string $command): bool
    {
        $dangerousCommands = [
            'rm -rf /',
            'sudo',
            'su ',
            'passwd',
            'chmod 777',
            'chown',
            'shutdown',
            'reboot',
            'halt',
            'init',
            'killall',
            'pkill',
            'kill -9',
            'dd if=',
            'format',
            'fdisk',
            'mount',
            'umount',
            'crontab',
            'systemctl',
            'service',
            ':(){:|:&};:',  // fork bomb
            'wget',
            'curl',
            'nc ',
            'netcat'
        ];

        $command = strtolower(trim($command));

        foreach ($dangerousCommands as $dangerous) {
            if (strpos($command, $dangerous) !== false) {
                return true;
            }
        }

        return false;
    }

    public function getDescription(): string
    {
        return 'Execute shell commands and interact with the linux operating system. Provides safe access to common system operations and utilities.';
    }

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

    public function getMergeSeparator(): ?string
    {
        return " && ";
    }

}

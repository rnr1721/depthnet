<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandExecutorInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Services\Agent\Plugins\DTO\CommandExecutionResult;
use App\Services\Agent\Plugins\DTO\CommandResult;
use App\Services\Agent\Plugins\DTO\ParsedCommand;
use Illuminate\Support\Facades\Log;

class CommandExecutor implements CommandExecutorInterface
{
    public function __construct(
        protected PluginRegistryInterface $pluginRegistry
    ) {
    }

    /**
     * @inheritDoc
     */
    public function executeCommands(array $commands, string $originalOutput): CommandExecutionResult
    {
        $results = [];
        $hasErrors = false;

        foreach ($commands as $command) {
            $result = $this->executeCommand($command);
            $results[] = $result;

            if (!$result->success) {
                $hasErrors = true;
            }
        }

        $formattedMessage = $this->formatMessage($originalOutput, $results);

        return new CommandExecutionResult($results, $formattedMessage, $hasErrors);
    }

    /**
     * @inheritDoc
     */
    protected function executeCommand(ParsedCommand $command): CommandResult
    {
        try {
            if (!$this->pluginRegistry->has($command->plugin)) {
                return new CommandResult(
                    $command,
                    '',
                    false,
                    "Unknown plugin: {$command->plugin}"
                );
            }

            $plugin = $this->pluginRegistry->get($command->plugin);

            if ($command->method === 'execute') {
                $result = $plugin->execute($command->content);
            } elseif ($plugin->hasMethod($command->method)) {
                $result = $plugin->callMethod($command->method, $command->content);
            } else {
                return new CommandResult(
                    $command,
                    '',
                    false,
                    "Method '{$command->method}' not found in plugin '{$command->plugin}'"
                );
            }

            return new CommandResult($command, $result, true);

        } catch (\Exception $e) {
            Log::error("Command execution error", [
                'plugin' => $command->plugin,
                'method' => $command->method,
                'error' => $e->getMessage()
            ]);

            return new CommandResult(
                $command,
                '',
                false,
                "Error executing {$command->plugin}::{$command->method}: " . $e->getMessage()
            );
        }
    }

    /**
     * Formats the output message with command results.
     *
     * @param string $originalOutput
     * @param CommandResult[] $results
     * @return string
     */
    protected function formatMessage(string $originalOutput, array $results): string
    {
        $formatted = $originalOutput . "\n\n" . "AGENT COMMAND RESULTS:" . "\n\n";

        foreach ($results as $i => $result) {
            $command = $result->command;
            $methodDisplay = $command->method === 'execute' ? '' : " {$command->method}";

            $formatted .= "SUCCESS: Command " . ($i + 1) . ": {$command->plugin}{$methodDisplay}\n";

            if (!empty($command->content)) {
                //$formatted .= "Input:\n" . $command->content . "\n\n";
            }

            if ($result->success) {
                $formatted .= "Result: \n" . $result->result . "\n\n";
            } else {
                $formatted .= "ERROR: " . $result->error . "\n\n";
            }

            $formatted .= str_repeat("-", 30) . "\n\n";
        }

        return $formatted;
    }
}

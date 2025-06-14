<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandExecutorInterface;
use App\Contracts\Agent\PluginManagerInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Services\Agent\Plugins\DTO\CommandExecutionResult;
use App\Services\Agent\Plugins\DTO\CommandResult;
use App\Services\Agent\Plugins\DTO\ParsedCommand;
use Psr\Log\LoggerInterface;

class CommandExecutor implements CommandExecutorInterface
{
    public function __construct(
        protected PluginRegistryInterface $pluginRegistry,
        protected PluginManagerInterface $pluginManager,
        protected LoggerInterface $logger
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
     * Execute single command with proper plugin configuration
     *
     * @inheritDoc
     */
    protected function executeCommand(ParsedCommand $command): CommandResult
    {
        try {
            // Check if plugin exists in registry
            if (!$this->pluginRegistry->has($command->plugin)) {
                return new CommandResult(
                    $command,
                    '',
                    false,
                    "Unknown plugin: {$command->plugin}"
                );
            }

            // Get plugin with ensured configuration from PluginManager
            $plugin = $this->pluginManager->getConfiguredPlugin($command->plugin);

            if (!$plugin) {
                return new CommandResult(
                    $command,
                    '',
                    false,
                    "Plugin '{$command->plugin}' is not available"
                );
            }

            // Check if plugin is enabled
            if (!$plugin->isEnabled()) {
                return new CommandResult(
                    $command,
                    '',
                    false,
                    "Plugin '{$command->plugin}' is disabled"
                );
            }

            // Execute command method
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
            $this->logger->error("Command execution error", [
                'plugin' => $command->plugin,
                'method' => $command->method,
                'content' => substr($command->content, 0, 100), // Log first 100 chars of content
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
     * Formats the output message with command results
     *
     * @param string $originalOutput
     * @param CommandResult[] $results
     * @return string
     */
    protected function formatMessage(
        string $originalOutput,
        array $results
    ): string {
        $formatted = $originalOutput . "\n\n" . "AGENT COMMAND RESULTS:" . "\n\n";

        foreach ($results as $i => $result) {
            $command = $result->command;

            $plugin = $this->pluginRegistry->get($command->plugin);
            $customSuccessMessage = $plugin->getCustomSuccessMessage();
            $customErrorMessage = $plugin->getCustomErrorMessage();

            $methodDisplay = $command->method === 'execute' ? '' : $command->method;

            $successMessage = $customSuccessMessage ?: "SUCCESS: {$command->plugin} {$methodDisplay}\n";
            $errorMessage = $customErrorMessage ?: "ERROR: {$command->plugin} {$methodDisplay}\n";

            $search = ['{method}'];
            $replace = [$methodDisplay];

            if ($customSuccessMessage) {
                $customSuccessMessage = str_replace($search, $replace, $customSuccessMessage);
            }

            if ($customErrorMessage) {
                $customErrorMessage = str_replace($search, $replace, $customErrorMessage);
            }

            if ($result->success) {
                $formatted .= $successMessage . "\n";
                if (!empty($result->result)) {
                    $formatted .= $result->result . "\n\n";
                }
            } else {
                $formatted .= $errorMessage . "\n";
                $formatted .= $result->error . "\n\n";
            }

            $formatted .= str_repeat("-", 30) . "\n\n";
        }

        return $formatted;
    }
}

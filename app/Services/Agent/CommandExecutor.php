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
    public function executeCommands(array $commands): CommandExecutionResult
    {
        $results = [];
        $hasErrors = false;

        $pluginExecutionMeta = [];
        foreach ($commands as $command) {
            $result = $this->executeCommand($command);
            $results[] = $result;
            $pluginExecutionMeta = $this->mergeExecutionMetaStrings($pluginExecutionMeta, $result->executionMeta);

            if (!$result->success) {
                $hasErrors = true;
            }
        }

        $formattedMessage = $this->formatMessage($results);

        return new CommandExecutionResult($results, $formattedMessage, $hasErrors, $pluginExecutionMeta);
    }

    /**
     * Merges execution meta strings from base and override arrays.
     *
     * @param array $base Base execution meta
     * @param array $override Override execution meta
     * @return array Merged execution meta
     */
    private function mergeExecutionMetaStrings(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (isset($base[$key]) && is_string($base[$key]) && is_string($value)) {
                $base[$key] .= ' ' . $value;
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
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

            $pluginExecutionMeta = [];
            // Execute command method
            if ($command->method === 'execute') {
                $result = $plugin->execute($command->content);
                $pluginExecutionMeta = $plugin->getPluginExecutionMeta();
            } elseif ($plugin->hasMethod($command->method)) {
                $result = $plugin->callMethod($command->method, $command->content);
                $pluginExecutionMeta = $plugin->getPluginExecutionMeta();
            } else {
                return new CommandResult(
                    $command,
                    '',
                    false,
                    "Method '{$command->method}' not found in command plugin '{$command->plugin}'"
                );
            }

            return new CommandResult($command, $result, true, null, $pluginExecutionMeta);

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
     * @param CommandResult[] $results
     * @return string
     */
    protected function formatMessage(
        array $results
    ): string {
        $formatted = "\n\n" . "<system_output_results>" . "\n" . '```system_command_results' . "\n\n";

        foreach ($results as $i => $result) {
            $command = $result->command;

            $plugin = $this->pluginRegistry->get($command->plugin);

            if (!$plugin) {
                $formatted .= '💀 SYSTEM COMMAND ERRORS. PLEASE ANALYSE AND CORRECT YOUR WAY';
                $formatted .= "\n\n";
                continue;
            }
            $customSuccessMessage = $plugin->getCustomSuccessMessage();
            $customErrorMessage = $plugin->getCustomErrorMessage();

            $methodDisplay = $command->method === 'execute' ? '' : $command->method;

            $successMessage = $customSuccessMessage ?: "⚡ SUCCESS: {$command->plugin} {$methodDisplay}\n";
            $errorMessage = $customErrorMessage ?: "⚠️ ERROR: {$command->plugin} {$methodDisplay}\n";

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

            $formatted .= str_repeat("-", 5) . "\n\n";
        }

        return $formatted . '```';
    }
}

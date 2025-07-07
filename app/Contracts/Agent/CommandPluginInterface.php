<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;

interface CommandPluginInterface
{
    /**
     * Set current model preset
     *
     * @param AiPreset $preset
     * @return void
     */
    public function setCurrentPreset(AiPreset $preset): void;

    /**
     * Get plugin (command) name
     *
     * @return string Command name
     */
    public function getName(): string;

    /**
     * Get command description
     *
     * @return string Command description
     */
    public function getDescription(): string;

    /**
     * Get message for agent when command is executed successfully
     * You can use {command} placeholder in the message
     *
     * @return string Icon URL or path
     */
    public function getCustomSuccessMessage(): ?string;

    /**
     * Get message for agent when command execution fails
     * You can use {command} placeholder in the message
     *
     * @return string Icon URL or path
     */
    public function getCustomErrorMessage(): ?string;

    /**
     * Execute command
     *
     * @param string $content Content to execute
     * @return string Result of command execution
     */
    public function execute(string $content): string;

    /**
     * Check if plugin has specific method
     *
     * @param string $method Method name
     * @return boolean Method exists?
     */
    public function hasMethod(string $method): bool;

    /**
     * Call specific method on plugin
     *
     * @param string $method Method name
     * @param string $content Content to pass to the method
     * @return string
     */
    public function callMethod(string $method, string $content): string;

    /**
     * Get available methods for this plugin
     *
     * @return array List of available methods
     */
    public function getAvailableMethods(): array;

    /**
     * Instructions for model
     *
     * @return array Instructions
     */
    public function getInstructions(): array;

    /**
     * Separator for merge similar commands
     * Used in smart command parser
     *
     * @return string|null Null will be "\n"
     */
    public function getMergeSeparator(): ?string;

    /**
     * Check if this plugin command data can be merged with others
     * Used in smart command parser to group similar commands
     *
     * @return bool True if can be merged, false otherwise
     */
    public function canBeMerged(): bool;

    /**
     * Get configuration fields for the plugin
     * Returns array of field definitions for UI
     *
     * @return array Array of field definitions
     */
    public function getConfigFields(): array;

    /**
     * Validate plugin configuration
     *
     * @param array $config Configuration to validate
     * @return array Array of validation errors (empty if valid)
     */
    public function validateConfig(array $config): array;

    /**
     * Update plugin configuration
     *
     * @param array $newConfig New configuration values
     * @return void
     */
    public function updateConfig(array $newConfig): void;

    /**
     * Get current plugin configuration
     *
     * @return array Current configuration
     */
    public function getConfig(): array;

    /**
     * Get default configuration for the plugin
     *
     * @return array Default configuration values
     */
    public function getDefaultConfig(): array;

    /**
     * Test if plugin is properly configured and working
     *
     * @return bool True if plugin is working
     */
    public function testConnection(): bool;

    /**
     * Check if plugin is enabled
     *
     * @return bool True if plugin is enabled
     */
    public function isEnabled(): bool;

    /**
     * Enable or disable plugin
     *
     * @param bool $enabled True to enable, false to disable
     * @return void
     */
    public function setEnabled(bool $enabled): void;

    /**
     * Here it is possible to return some data that may affect the agent's work.
     * This is system things
     *
     * @return array
     */
    public function getPluginExecutionMeta(): array;

    /**
     * This method can be used for make placeholders for plugin or some things,
     * that need to do, when plugin will initialized
     *
     * @return void
     */
    public function pluginReady(): void;

    /**
     * Get list of self-closing tags for this plugin
     * These tags don't require content and will be auto-closed
     * Example: ['pause', 'resume', 'status'] for agent plugin
     *
     * @return array List of method names that are self-closing
     */
    public function getSelfClosingTags(): array;
}

<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;

interface CommandPluginInterface
{
    /**
     * Get plugin name (used as command tag, e.g. "memory" → [memory]...[/memory])
     *
     * @return string Command name
     */
    public function getName(): string;

    /**
     * Get plugin description for instructions
     *
     * @return string Command description
     */
    public function getDescription(): string;

    /**
     * Get usage instructions shown to the AI
     *
     * @return array Instructions
     */
    public function getInstructions(): array;

    /**
     * Execute default command.
     * Preset is passed explicitly — plugin does not store it internally.
     *
     * @param string $content Content to execute
     * @return string Result of command execution
     */
    public function execute(string $content, AiPreset $preset): string;

    /**
     * Called when a preset is applied to the registry.
     * Use to register placeholders/shortcodes scoped to this preset.
     * Preset is passed explicitly — no internal storage needed.
     *
     * @return void
     */
    public function pluginReady(AiPreset $preset): void;

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
     * Get default config values for the plugin
     *
     * @return array Default configuration values
     */
    public function getDefaultConfig(): array;

    /**
     * Update plugin config at runtime
     *
     * @param array $newConfig New configuration values
     * @return void
     */
    public function updateConfig(array $config): void;

    /**
     * Test plugin connectivity / availability
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
     * @param AiPreset $preset
     * @return string
     */
    public function callMethod(string $method, string $content, AiPreset $preset): string;

    /**
     * Get available method names for this plugin
     *
     * @return array List of available methods
     */
    public function getAvailableMethods(): array;

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
     * Check if this plugin command data can be merged with others
     * Used in smart command parser to group similar commands
     *
     * @return bool True if can be merged, false otherwise
     */
    public function canBeMerged(): bool;

    /**
     * Separator for merge similar commands
     * Used in smart command parser
     *
     * @return string|null Null will be "\n"
     */
    public function getMergeSeparator(): ?string;

    /**
     * Get list of self-closing tags for this plugin
     * These tags don't require content and will be auto-closed
     * Example: ['pause', 'resume', 'status'] for agent plugin
     *
     * @return array List of method names that are self-closing
     */
    public function getSelfClosingTags(): array;

    /**
     * Here it is possible to return some data that may affect the agent's work.
     * This is system things
     *
     * @return array
     */
    public function getPluginExecutionMeta(): array;

    /**
     * Get current plugin configuration
     *
     * @return array Current configuration
     */
    public function getConfig(): array;

}

<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;

/**
 * Plugin Registry Interface
 *
 * Manages command plugins with support for runtime enable/disable functionality.
 * All methods except `allRegistered()` respect the disabled plugins list.
 */
interface PluginRegistryInterface
{
    /**
     * Register a command plugin in the registry
     *
     * @param CommandPluginInterface $plugin Plugin instance to register
     * @return self Returns self for method chaining
     */
    public function register(CommandPluginInterface $plugin): self;

    /**
     * Check if plugin is registered and currently available (not disabled)
     *
     * @param string $name Plugin name to check
     * @return bool True if plugin exists and is not disabled, false otherwise
     */
    public function has(string $name): bool;

    /**
     * Get plugin instance by name if it's available (not disabled)
     *
     * @param string $name Plugin name to retrieve
     * @return CommandPluginInterface|null Plugin instance or null if not found/disabled
     */
    public function get(string $name): ?CommandPluginInterface;

    /**
     * Get all currently available (not disabled) plugin instances
     *
     * @return CommandPluginInterface[] Array of available plugin instances indexed by name
     */
    public function all(): array;

    /**
     * Get all registered plugin instances regardless of disabled status
     *
     * This method bypasses the disabled plugins filter and returns all registered plugins.
     * Useful for administrative purposes or debugging.
     *
     * @return CommandPluginInterface[] Array of all registered plugin instances indexed by name
     */
    public function allRegistered(): array;

    /**
     * Get names of all currently available (not disabled) plugins
     *
     * @return string[] Array of available plugin names
     */
    public function getAvailablePluginNames(): array;

    /**
     * Set current AI preset for all available plugins
     *
     * Only affects plugins that are currently available (not disabled).
     * Each plugin will receive the preset and can use it to configure its behavior.
     *
     * @param AiPreset $preset AI preset to set for plugins
     * @return void
     */
    public function setCurrentPreset(AiPreset $preset): void;

    /**
     * Set plugins to be temporarily disabled
     *
     * Disabled plugins will be excluded from all registry operations except `allRegistered()`.
     * This allows runtime control over which plugins are available without unregistering them.
     *
     * @param array|string $disabledPlugins Plugin names to disable.
     *                                      Can be an array of names or comma-separated string.
     * @return void
     */
    public function setDisabledForNow(array|string $disabledPlugins): void;
}

<?php

namespace App\Contracts\Agent\Plugins;

use App\Models\AiPreset;

/**
 * Interface for plugin metadata service operations
 */
interface PluginMetadataServiceInterface
{
    /**
     * Get plugin metadata value
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function get(AiPreset $preset, string $pluginName, ?string $key = null, $default = null);

    /**
     * Set plugin metadata value
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function set(AiPreset $preset, string $pluginName, string $key, $value): bool;

    /**
     * Update multiple plugin metadata values
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @param array $data
     * @return bool
     */
    public function update(AiPreset $preset, string $pluginName, array $data): bool;

    /**
     * Remove plugin metadata key
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @param string $key
     * @return bool
     */
    public function remove(AiPreset $preset, string $pluginName, string $key): bool;

    /**
     * Check if plugin metadata key exists
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @param string|null $key
     * @return bool
     */
    public function has(AiPreset $preset, string $pluginName, ?string $key = null): bool;

    /**
     * Clear all metadata for specific plugin
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @return bool
     */
    public function clear(AiPreset $preset, string $pluginName): bool;

    /**
     * Increment numeric plugin metadata value
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @param string $key
     * @param int $amount
     * @param int|null $max
     * @return bool
     */
    public function increment(AiPreset $preset, string $pluginName, string $key, int $amount = 1, ?int $max = null): bool;

    /**
     * Decrement numeric plugin metadata value
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @param string $key
     * @param int $amount
     * @param int|null $min
     * @return bool
     */
    public function decrement(AiPreset $preset, string $pluginName, string $key, int $amount = 1, ?int $min = null): bool;

    /**
     * Set numeric plugin metadata value with constraints
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @param string $key
     * @param int $value
     * @param int|null $min
     * @param int|null $max
     * @return bool
     */
    public function setNumeric(AiPreset $preset, string $pluginName, string $key, int $value, ?int $min = null, ?int $max = null): bool;

    /**
     * Export all plugin metadata
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @return array
     */
    public function export(AiPreset $preset, string $pluginName): array;

    /**
     * Import plugin metadata
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @param array $metadata
     * @param bool $merge
     * @return bool
     */
    public function import(AiPreset $preset, string $pluginName, array $metadata, bool $merge = true): bool;

    /**
     * Get list of all plugins that have metadata
     *
     * @param AiPreset $preset
     * @return array
     */
    public function getPluginList(AiPreset $preset): array;

    /**
     * Check if plugin has any metadata
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @return bool
     */
    public function hasPlugin(AiPreset $preset, string $pluginName): bool;

    /**
     * Get metadata for all plugins
     *
     * @param AiPreset $preset
     * @return array
     */
    public function getAllPluginMetadata(AiPreset $preset): array;

    /**
     * Clear all plugin metadata for preset
     *
     * @param AiPreset $preset
     * @return bool
     */
    public function clearAll(AiPreset $preset): bool;

    /**
     * Search plugin metadata by value
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @param mixed $searchValue
     * @param bool $strict
     * @return array
     */
    public function search(AiPreset $preset, string $pluginName, $searchValue, bool $strict = false): array;

    /**
     * Copy plugin metadata from one preset to another
     *
     * @param AiPreset $sourcePreset
     * @param AiPreset $targetPreset
     * @param string $pluginName
     * @param bool $merge
     * @return bool
     */
    public function copyPlugin(AiPreset $sourcePreset, AiPreset $targetPreset, string $pluginName, bool $merge = true): bool;

    /**
     * Move plugin metadata from one plugin name to another within same preset
     *
     * @param AiPreset $preset
     * @param string $fromPluginName
     * @param string $toPluginName
     * @return bool
     */
    public function movePlugin(AiPreset $preset, string $fromPluginName, string $toPluginName): bool;

    /**
     * Get plugin metadata statistics
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @return array
     */
    public function getPluginStats(AiPreset $preset, string $pluginName): array;

    /**
     * Get statistics for all plugins in preset
     *
     * @param AiPreset $preset
     * @return array
     */
    public function getAllPluginStats(AiPreset $preset): array;

    /**
     * Validate plugin name
     *
     * @param string $pluginName
     * @return array
     */
    public function validatePluginName(string $pluginName): array;
}

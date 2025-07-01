<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;

/**
 * Interface for metadata service operations
 */
interface PresetMetadataServiceInterface
{
    /**
     * Get metadata value by key or all metadata
     *
     * @param AiPreset $preset
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function get(AiPreset $preset, ?string $key = null, $default = null);

    /**
     * Set metadata value by key
     *
     * @param AiPreset $preset
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function set(AiPreset $preset, string $key, $value): bool;

    /**
     * Update multiple metadata values at once
     *
     * @param AiPreset $preset
     * @param array $data
     * @return bool
     */
    public function update(AiPreset $preset, array $data): bool;

    /**
     * Remove metadata key
     *
     * @param AiPreset $preset
     * @param string $key
     * @return bool
     */
    public function remove(AiPreset $preset, string $key): bool;

    /**
     * Check if metadata key exists
     *
     * @param AiPreset $preset
     * @param string $key
     * @return bool
     */
    public function has(AiPreset $preset, string $key): bool;

    /**
     * Clear all metadata or specific namespace
     *
     * @param AiPreset $preset
     * @param string|null $namespace
     * @return bool
     */
    public function clear(AiPreset $preset, ?string $namespace = null): bool;

    /**
     * Get metadata for specific plugin/namespace
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function getPluginMetadata(AiPreset $preset, string $pluginName, ?string $key = null, $default = null);

    /**
     * Set metadata for specific plugin/namespace
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function setPluginMetadata(AiPreset $preset, string $pluginName, string $key, $value): bool;

    /**
     * Update multiple values for specific plugin
     *
     * @param AiPreset $preset
     * @param string $pluginName
     * @param array $data
     * @return bool
     */
    public function updatePluginMetadata(AiPreset $preset, string $pluginName, array $data): bool;

    /**
     * Increment numeric metadata value
     *
     * @param AiPreset $preset
     * @param string $key
     * @param int $amount
     * @param int|null $max
     * @return bool
     */
    public function increment(AiPreset $preset, string $key, int $amount = 1, ?int $max = null): bool;

    /**
     * Decrement numeric metadata value
     *
     * @param AiPreset $preset
     * @param string $key
     * @param int $amount
     * @param int|null $min
     * @return bool
     */
    public function decrement(AiPreset $preset, string $key, int $amount = 1, ?int $min = null): bool;

    /**
     * Set numeric value with min/max constraints
     *
     * @param AiPreset $preset
     * @param string $key
     * @param int $value
     * @param int|null $min
     * @param int|null $max
     * @return bool
     */
    public function setNumeric(AiPreset $preset, string $key, int $value, ?int $min = null, ?int $max = null): bool;

    /**
     * Export preset metadata as array
     *
     * @param AiPreset $preset
     * @param string|null $namespace
     * @return array
     */
    public function export(AiPreset $preset, ?string $namespace = null): array;

    /**
     * Import metadata from array
     *
     * @param AiPreset $preset
     * @param array $metadata
     * @param string|null $namespace
     * @param bool $merge
     * @return bool
     */
    public function import(AiPreset $preset, array $metadata, ?string $namespace = null, bool $merge = true): bool;

    /**
     * Validate metadata structure
     *
     * @param array $metadata
     * @return array
     */
    public function validate(array $metadata): array;
}

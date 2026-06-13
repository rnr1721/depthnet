<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;
use App\Models\PresetPluginData;
use Illuminate\Support\Collection;

interface PresetPluginDataServiceInterface
{
    /**
     * Return all entries for a plugin as a keyed collection [key => entry].
     *
     * @param AiPreset $preset
     * @param string $pluginCode
     * @return Collection
     */
    public function all(AiPreset $preset, string $pluginCode): Collection;

    /**
     * Return a flat [key => value] map — convenient for plugin execute().
     *
     * @param AiPreset $preset
     * @param string $pluginCode
     * @return array
     */
    public function map(AiPreset $preset, string $pluginCode): array;

    /**
     * Find a single entry by key. Returns null if not found.
     *
     * @param AiPreset $preset
     * @param string $pluginCode
     * @param string $key
     * @return PresetPluginData|null
     */
    public function find(
        AiPreset $preset,
        string $pluginCode,
        string $key
    ): ?PresetPluginData;

    /**
     * Create a new entry. Throws if the key already exists.
     *
     * @param AiPreset $preset
     * @param string $pluginCode
     * @param string $key
     * @param string $value
     * @param integer $position
     * @return PresetPluginData
     */
    public function create(
        AiPreset $preset,
        string $pluginCode,
        string $key,
        string $value,
        int $position = 0
    ): PresetPluginData;

    /**
     * Update an existing entry by its ID.
     * Only non-null fields are updated (partial update safe).
     */
    public function update(int $id, array $data): PresetPluginData;

    /**
     * Delete an entry by ID. Silently does nothing if not found.
     *
     * @param integer $id
     * @return void
     */
    public function delete(int $id): void;

    /**
     * Delete all entries for a plugin on a preset.
     *
     * @param AiPreset $preset
     * @param string $pluginCode
     * @return integer
     */
    public function clear(AiPreset $preset, string $pluginCode): int;

    /**
     * Reorder entries by providing an ordered list of IDs.
     * Assigns position = array index.
     *
     * @param array $orderedIds
     * @return void
     */
    public function reorder(array $orderedIds): void;
}

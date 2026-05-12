<?php

namespace App\Services\Agent\Plugins\Related\PluginData;

use App\Contracts\Agent\PresetPluginDataServiceInterface;
use App\Models\AiPreset;
use App\Models\PresetPluginData;
use Illuminate\Support\Collection;

/**
 * CRUD service for preset_plugin_data.
 *
 * Plugins that declare a `plugin_data_list` config field use this service
 * to persist their named entries. The service is intentionally plugin-agnostic:
 * it knows nothing about what keys or values mean — that's the plugin's concern.
 */
class PresetPluginDataService implements PresetPluginDataServiceInterface
{
    public function __construct(
        protected PresetPluginData $model
    ) {
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * @inheritDoc
     */
    public function all(AiPreset $preset, string $pluginCode): Collection
    {
        return $this->model->forPlugin($preset->id, $pluginCode)
            ->ordered()
            ->get()
            ->keyBy('key');
    }

    /**
     * @inheritDoc
     */
    public function map(AiPreset $preset, string $pluginCode): array
    {
        return $this->model->forPlugin($preset->id, $pluginCode)
            ->ordered()
            ->pluck('value', 'key')
            ->all();
    }

    /**
     * @inheritDoc
     */
    public function find(AiPreset $preset, string $pluginCode, string $key): ?PresetPluginData
    {
        return $this->model->forPlugin($preset->id, $pluginCode)
            ->where('key', $key)
            ->first();
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * @inheritDoc
     */
    public function create(AiPreset $preset, string $pluginCode, string $key, string $value, int $position = 0): PresetPluginData
    {
        return $this->model->create([
            'preset_id'   => $preset->id,
            'plugin_code' => $pluginCode,
            'key'         => $key,
            'value'       => $value,
            'position'    => $position,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function update(int $id, array $data): PresetPluginData
    {
        $entry = $this->model->findOrFail($id);

        $fillable = array_filter([
            'key'      => $data['key'] ?? null,
            'value'    => $data['value'] ?? null,
            'position' => $data['position'] ?? null,
        ], fn ($v) => $v !== null);

        $entry->update($fillable);

        return $entry->fresh();
    }

    /**
     * @inheritDoc
     */
    public function delete(int $id): void
    {
        $this->model->find($id)?->delete();
    }

    /**
     * @inheritDoc
     */
    public function clear(AiPreset $preset, string $pluginCode): int
    {
        return $this->model->forPlugin($preset->id, $pluginCode)->delete();
    }

    /**
     * @inheritDoc
     */
    public function reorder(array $orderedIds): void
    {
        foreach ($orderedIds as $position => $id) {
            $this->model->where('id', $id)->update(['position' => $position]);
        }
    }
}

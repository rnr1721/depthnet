<?php

namespace App\Services\Agent\Enricher\Services;

use App\Contracts\Agent\PresetRagConfigServiceInterface;
use App\Models\PresetRagConfig;
use Illuminate\Support\Collection;

class PresetRagConfigService implements PresetRagConfigServiceInterface
{
    /**
     * Default sources assigned when none are specified on creation.
     */
    private const DEFAULT_SOURCES = ['vector_memory', 'journal', 'skills'];

    public function __construct(
        protected PresetRagConfig $presetRagConfigModel
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getOrdered(int $presetId): Collection
    {
        return $this->presetRagConfigModel->where('preset_id', $presetId)
            ->with('ragPreset:id,name,engine_name,is_active')
            ->ordered()
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function create(int $presetId, array $data): PresetRagConfig
    {
        $ragPresetId = $data['rag_preset_id'];

        if ($this->presetRagConfigModel->where('preset_id', $presetId)->where('rag_preset_id', $ragPresetId)->exists()) {
            throw new \InvalidArgumentException('This RAG preset is already added.');
        }

        $isFirst   = !$this->presetRagConfigModel->where('preset_id', $presetId)->exists();
        $sortOrder = (int) $this->presetRagConfigModel->where('preset_id', $presetId)->max('sort_order') + 1;

        $config = $this->presetRagConfigModel->create(array_merge($data, [
            'preset_id'  => $presetId,
            'is_primary' => $isFirst,
            'sort_order' => $isFirst ? 0 : $sortOrder,
            'sources'    => $data['sources'] ?? self::DEFAULT_SOURCES,
        ]));

        $config->load('ragPreset:id,name,engine_name,is_active');

        return $config;
    }

    /**
     * @inheritDoc
     */
    public function update(int $presetId, int $configId, array $data): PresetRagConfig
    {
        $config = $this->presetRagConfigModel->where('preset_id', $presetId)->findOrFail($configId);

        $config->update($data);

        return $config->fresh();
    }

    /**
     * @inheritDoc
     */
    public function delete(int $presetId, int $configId): void
    {
        $config     = $this->presetRagConfigModel->where('preset_id', $presetId)->findOrFail($configId);
        $wasPrimary = $config->is_primary;

        $config->delete();

        if ($wasPrimary) {
            $next = $this->presetRagConfigModel->where('preset_id', $presetId)->ordered()->first();
            $next?->update(['is_primary' => true]);
        }
    }

    /**
     * @inheritDoc
     */
    public function reorder(int $presetId, array $ids): void
    {
        $configs = $this->presetRagConfigModel->where('preset_id', $presetId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $order => $id) {
            if ($configs->has($id)) {
                $configs[$id]->update([
                    'sort_order' => $order,
                    'is_primary' => $order === 0,
                ]);
            }
        }
    }
}

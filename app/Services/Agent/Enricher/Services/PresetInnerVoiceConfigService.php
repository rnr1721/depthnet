<?php

namespace App\Services\Agent\Enricher\Services;

use App\Contracts\Agent\PresetInnerVoiceConfigServiceInterface;
use App\Models\PresetInnerVoiceConfig;
use Illuminate\Support\Collection;

class PresetInnerVoiceConfigService implements PresetInnerVoiceConfigServiceInterface
{
    public function __construct(
        protected PresetInnerVoiceConfig $model
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getOrdered(int $presetId): Collection
    {
        return $this->model
            ->where('preset_id', $presetId)
            ->with('voicePreset:id,name,engine_name,is_active')
            ->ordered()
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function create(int $presetId, array $data): PresetInnerVoiceConfig
    {
        $voicePresetId = $data['voice_preset_id'];

        if ($this->model->where('preset_id', $presetId)->where('voice_preset_id', $voicePresetId)->exists()) {
            throw new \InvalidArgumentException('This voice preset is already added.');
        }

        $sortOrder = (int) $this->model->where('preset_id', $presetId)->max('sort_order') + 1;

        $config = $this->model->create(array_merge($data, [
            'preset_id'  => $presetId,
            'sort_order' => $sortOrder,
        ]));

        $config->load('voicePreset:id,name,engine_name,is_active');

        return $config;
    }

    /**
     * @inheritDoc
     */
    public function update(int $presetId, int $configId, array $data): PresetInnerVoiceConfig
    {
        $config = $this->model->where('preset_id', $presetId)->findOrFail($configId);
        $config->update($data);

        return $config->fresh();
    }

    /**
     * @inheritDoc
     */
    public function delete(int $presetId, int $configId): void
    {
        $this->model->where('preset_id', $presetId)->findOrFail($configId)->delete();
    }

    /**
     * @inheritDoc
     */
    public function reorder(int $presetId, array $ids): void
    {
        $configs = $this->model
            ->where('preset_id', $presetId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $order => $id) {
            if ($configs->has($id)) {
                $configs[$id]->update(['sort_order' => $order]);
            }
        }
    }
}

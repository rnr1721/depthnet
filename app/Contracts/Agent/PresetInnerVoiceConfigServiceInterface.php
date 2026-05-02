<?php

namespace App\Contracts\Agent;

use App\Models\PresetInnerVoiceConfig;
use Illuminate\Support\Collection;

interface PresetInnerVoiceConfigServiceInterface
{
    /**
     * Get all configs for a preset ordered by sort_order.
     */
    public function getOrdered(int $presetId): Collection;

    /**
     * Create a new inner voice config for a preset.
     *
     * @throws \InvalidArgumentException if voice_preset_id is already added
     */
    public function create(int $presetId, array $data): PresetInnerVoiceConfig;

    /**
     * Update an existing config.
     */
    public function update(int $presetId, int $configId, array $data): PresetInnerVoiceConfig;

    /**
     * Delete a config.
     */
    public function delete(int $presetId, int $configId): void;

    /**
     * Reorder configs by providing an ordered array of IDs.
     *
     * @param int[] $ids
     */
    public function reorder(int $presetId, array $ids): void;
}

<?php

namespace App\Contracts\Agent;

use App\Models\PresetRagConfig;
use Illuminate\Support\Collection;

interface PresetRagConfigServiceInterface
{
    /**
     * Get all RAG configs for a preset, ordered for pipeline execution.
     * Eager-loads ragPreset relation.
     *
     * @return Collection<int, PresetRagConfig>
     */
    public function getOrdered(int $presetId): Collection;

    /**
     * Create a new RAG config for a preset.
     * Automatically assigns sort_order and sets is_primary if it's the first config.
     * Throws if the (preset_id, rag_preset_id) pair already exists.
     *
     * @throws \InvalidArgumentException
     */
    public function create(int $presetId, array $data): PresetRagConfig;

    /**
     * Update an existing RAG config.
     * Only the fields present in $data are updated.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function update(int $presetId, int $configId, array $data): PresetRagConfig;

    /**
     * Delete a RAG config.
     * If the deleted config was primary, promotes the next one automatically.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function delete(int $presetId, int $configId): void;

    /**
     * Reorder configs by the given ID sequence.
     * The first ID in the array becomes primary.
     * IDs not belonging to the preset are silently ignored.
     */
    public function reorder(int $presetId, array $ids): void;
}

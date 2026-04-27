<?php

namespace App\Contracts\Agent\Enricher;

use App\Models\AiPreset;
use Illuminate\Support\Collection;

interface EnricherFactoryInterface
{
    /**
     * Returns the context enricher (inner voice / cycle prompt).
     * Responsible for injecting [[inner_voice]] into the agent's system prompt.
     */
    public function makeContextEnricher(): ContextEnricherInterface;

    /**
     * Returns the RAG enricher singleton.
     * For single-pass use or internal calls.
     * The multi-RAG pipeline calls enrichWithConfig() on this same instance.
     */
    public function makeRagEnricher(): RagContextEnricherInterface;

    /**
     * Returns the Person context enricher.
     * Used directly only when persons enrichment is needed outside the RAG pipeline.
     * Within the RAG pipeline it is triggered automatically when a config has
     * 'persons' in its sources list.
     */
    public function makePersonEnricher(): PersonContextEnricherInterface;

    /**
     * Returns the ordered RAG configs for a preset.
     * Context builders iterate over these and call enrichWithConfig() on each,
     * passing the accumulated $seenIds by reference for cross-config deduplication.
     *
     * Returns an empty Collection when the preset has no RAG configs.
     *
     * @return Collection<int, \App\Models\PresetRagConfig>
     */
    public function getOrderedRagConfigs(AiPreset $preset): Collection;
}

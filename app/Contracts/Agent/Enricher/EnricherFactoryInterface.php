<?php

namespace App\Contracts\Agent\Enricher;

use App\Models\AiPreset;
use Illuminate\Support\Collection;

interface EnricherFactoryInterface
{
    /**
     * Create an InnerVoiceEnricher instance.
     *
     * Used by CycleContextBuilder and SingleContextBuilder to run the
     * multi-voice pipeline. Each enabled PresetInnerVoiceConfig is passed
     * to enrich() independently — one call per voice preset. Results are
     * concatenated into a single [[inner_voice]] shortcode block, with each
     * response wrapped in a labeled section (config->label or preset name).
     *
     * Errors in individual voices are caught internally and never propagate.
     */
    public function makeInnerVoiceEnricher(): InnerVoiceEnricherInterface;

    /**
     * Create a CyclePromptEnricher instance.
     *
     * Used exclusively in CycleContextBuilder as an anti-loop mechanism.
     * Calls the preset configured in cycle_prompt_preset_id, and writes
     * its output directly into InputPoolService — not into [[inner_voice]].
     *
     * Conceptually distinct from inner voices: its goal is not to enrich
     * the system prompt but to inject an impulse (critique, redirection,
     * noise) into the conversation turn before the next thinking cycle.
     */
    public function makeCyclePromptEnricher(): CyclePromptEnricherInterface;

    /**
     * Create a RagContextEnricher instance.
     *
     * Context builders iterate over getOrderedRagConfigs() and call
     * enrichWithConfig() on this instance for each config, passing
     * $seenIds by reference for cross-config deduplication.
     */
    public function makeRagEnricher(): RagContextEnricherInterface;

    /**
     * Create a PersonContextEnricher instance.
     *
     * Builds [[persons_context]] from person memory facts relevant to the
     * current conversation. Used directly when persons enrichment is needed
     * outside the RAG pipeline; within the RAG pipeline it is triggered
     * automatically when a config has 'persons' in its sources list.
     */
    public function makePersonEnricher(): PersonContextEnricherInterface;

    /**
     * Get all RAG configs for a preset ordered by sort_order.
     *
     * Context builders iterate over these and call enrichWithConfig() on each,
     * passing the accumulated $seenIds by reference for cross-config deduplication.
     *
     * Returns an empty Collection when the preset has no RAG configs.
     *
     * @return Collection<int, \App\Models\PresetRagConfig>
     */
    public function getOrderedRagConfigs(AiPreset $preset): Collection;

    /**
     * Get all enabled inner voice configs for a preset ordered by sort_order.
     *
     * Context builders iterate over these and call InnerVoiceEnricher::enrich()
     * for each config. Disabled configs are excluded automatically.
     *
     * Returns an empty Collection when the preset has no voice configs,
     * in which case [[inner_voice]] is simply not registered.
     *
     * @return Collection<int, \App\Models\PresetInnerVoiceConfig>
     */
    public function getOrderedVoiceConfigs(AiPreset $preset): Collection;
}

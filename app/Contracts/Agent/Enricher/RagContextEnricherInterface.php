<?php

namespace App\Contracts\Agent\Enricher;

use App\Models\AiPreset;
use App\Models\PresetRagConfig;

/**
 * RagContextEnricherInterface
 *
 * Enriches the agent context with RAG (Retrieval-Augmented Generation) data
 * before the main model receives it.
 *
 * Two entry points:
 *
 *  enrich()           — legacy/simple call. Loads the first ragConfig from
 *                       the preset itself and runs with empty seenIds.
 *                       Keeps all existing callers working without changes.
 *
 *  enrichWithConfig() — used by the multi-RAG pipeline in context builders.
 *                       Caller supplies an explicit PresetRagConfig and the
 *                       accumulated seenIds from previous configs so this
 *                       pass can skip already-retrieved records.
 */
interface RagContextEnricherInterface extends EnricherInterface
{
    /**
     * Run RAG enrichment for a specific config slot.
     *
     * @param AiPreset        $preset   Main preset being enriched
     * @param array           $context  Current conversation messages
     * @param PresetRagConfig $config   Config that drives this pass
     * @param array<string,true> $seenIds  Already-retrieved record keys (namespaced)
     *                                    mutated by reference so callers accumulate state
     * @return EnricherResponseInterface
     */
    public function enrichWithConfig(
        AiPreset $preset,
        array $context,
        PresetRagConfig $config,
        array &$seenIds = []
    ): EnricherResponseInterface;
}

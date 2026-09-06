<?php

namespace App\Contracts\Agent\Enricher\Rag;

use App\Models\AiPreset;
use App\Services\Agent\Enricher\Rag\RagAssembly;

/**
 * Assembles RAG payloads and applies them to a thinking cycle.
 *
 * Splits the old context-builder RAG block into two halves:
 *
 *   assemble()        — pure retrieval + payload construction. No shortcode
 *                       registration, no [[rag_context]] side effect. Cacheable.
 *                       Warm-up calls this; the real cycle calls it for the
 *                       non-warmable (or all) configs.
 *
 *   applyToContext()  — merge payloads, format, register [[rag_context]] on the
 *                       target presets. The side-effecting half the real cycle runs.
 *
 * Per-config UI system messages (RAG visibility) are emitted inside assemble()
 * only when $emitSystemMessages is true — the real cycle keeps them, warm-up
 * suppresses them.
 */
interface RagPipelineServiceInterface
{
    /**
     * Retrieve and build payloads. Pure w.r.t. shortcodes/context.
     *
     * @param  AiPreset             $thinking            The preset that thinks (mode source)
     * @param  AiPreset             $source              The RAG source preset
     * @param  bool                 $extended            Active context mode (extended vs normal)
     * @param  int[]                $onlyConfigIds       When non-empty, assemble ONLY these config ids
     *                                                   (warm-up: prewarmable ids). Empty = all configs.
     * @param  array<string,true>   $seenIds             Retrieval dedup state, BY REFERENCE — resumes
     *                                                   from a warm pass and keeps accumulating.
     * @param  bool                 $emitSystemMessages  Emit per-config UI messages (real cycle: true,
     *                                                   warm-up: false).
     * @return RagAssembly
     */
    public function assemble(
        AiPreset $thinking,
        AiPreset $source,
        bool $extended,
        array $onlyConfigIds = [],
        array &$seenIds = [],
        bool $emitSystemMessages = true,
        ?array $context = null,
    ): RagAssembly;

    /**
     * Merge payloads, format, register [[rag_context]] (and nothing else) on
     * both the source and thinking preset ids.
     *
     * @param  RagAssembly $assembly  Payloads to render (warm + fresh already concatenated)
     * @param  AiPreset    $thinking
     * @param  AiPreset    $source
     */
    public function applyToContext(RagAssembly $assembly, AiPreset $thinking, AiPreset $source): void;

    /**
     * Resolve the RAG assembly for a real thinking cycle, using the warm cache
     * when present (warm payloads + synchronous non-prewarmable configs), or a
     * full synchronous assembly on a cold miss. Does not write to the cache.
     *
     * @param AiPreset $thinking
     * @param AiPreset $source
     * @param boolean $extended
     * @param array $context The cycle's already-built message window.
     * @return RagAssembly
     */
    public function resolveForCycle(
        AiPreset $thinking,
        AiPreset $source,
        bool $extended,
        array $context,
    ): RagAssembly;
}

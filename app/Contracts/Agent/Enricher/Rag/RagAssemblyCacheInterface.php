<?php

namespace App\Contracts\Agent\Enricher\Rag;

use App\Services\Agent\Enricher\Rag\RagAssembly;

/**
 * Ephemeral cache for warm RAG assemblies.
 *
 * A warm assembly is valid only from the moment the agent last spoke until the
 * next input event. It is deliberately short-lived: on a miss the pipeline just
 * assembles synchronously, losing nothing but time. TTL is a safety net for the
 * case where speech-based invalidation is somehow missed — the entry expires on
 * its own rather than serving stale RAG forever.
 *
 * Keyed by (preset_id, context_mode): a normal-mode warm assembly must never be
 * served to an extended-mode cycle, since the two filter RAG configs differently.
 */
interface RagAssemblyCacheInterface
{
    public function get(int $presetId, string $contextMode): ?RagAssembly;

    public function put(int $presetId, string $contextMode, RagAssembly $assembly): void;

    /**
     * Invalidate every mode for this preset. Called when the agent speaks —
     * the single moment that ends the silent-stretch cache (Part 1) and,
     * separately, triggers the warm-up refill (Part 2).
     */
    public function forget(int $presetId): void;
}

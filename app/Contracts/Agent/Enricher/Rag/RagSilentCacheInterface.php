<?php

namespace App\Contracts\Agent\Enricher\Rag;

use App\Services\Agent\Enricher\Rag\RagAssembly;

/**
 * Silent-stretch cache: holds the FULL RAG assembly across an agent's
 * multi-cycle silent act (thinking, tool calls, Continue — before speech).
 *
 * Distinct from the warm cache (prewarmable-only, lives from speech to the next
 * user message). These two are never warm at the same time — speech ends the
 * silent stretch and begins the warm window — so they live under separate keys
 * with separate meaning: silent = full assembly frozen for the act, warm =
 * background levels prewarmed for the next reply.
 */
interface RagSilentCacheInterface
{
    public function get(int $presetId, string $contextMode): ?RagAssembly;

    public function put(int $presetId, string $contextMode, RagAssembly $assembly): void;

    /** Invalidate all modes for this preset. Called on speech, alongside the warm cache. */
    public function forget(int $presetId): void;
}

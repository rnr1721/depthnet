<?php

namespace App\Contracts\Agent\Rag;

use App\Models\AiPreset;

/**
 * Enriches the agent context with RAG (Retrieval-Augmented Generation) data
 * before the main model receives it.
 *
 * The enricher:
 *  1. Checks whether the preset has RAG enabled and a RAG-preset configured.
 *  2. Uses a lightweight "query-formulation" model (the RAG preset) to turn
 *     the recent conversation into a focused search query.
 *  3. Runs an associative vector-memory search against the RAG preset's store.
 *  4. Returns the retrieved fragments as a string ready to be injected into
 *     the system prompt / context.
 */
interface RagContextEnricherInterface
{
    /**
     * Retrieve relevant memory fragments for the given preset and recent context.
     *
     * @param AiPreset $preset      The main (thinking) preset.
     * @param array    $context     The conversation context built so far
     *                              (array of ['role' => ..., 'content' => ...]).
     *
     * @return string|null          Formatted RAG block to prepend to the context,
     *                              or null when RAG is disabled / nothing found.
     */
    public function enrich(AiPreset $preset, array $context): ?string;
}

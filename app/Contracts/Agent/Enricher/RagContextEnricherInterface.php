<?php

namespace App\Contracts\Agent\Enricher;

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
interface RagContextEnricherInterface extends EnricherInterface
{
}

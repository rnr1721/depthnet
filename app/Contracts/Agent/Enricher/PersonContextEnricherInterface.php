<?php

namespace App\Contracts\Agent\Enricher;

use App\Contracts\Agent\Enricher\Rag\RagSectionInterface;
use App\Models\AiPreset;

/**
 * Builds person-related context blocks.
 *
 * Two modes:
 *   - enrich()          : legacy — returns a full text block wrapped with
 *                          [PERSONS CONTEXT]/[END PERSONS CONTEXT] markers,
 *                          intended for standalone use.
 *   - enrichAsSection() : new — returns a RagSection of type 'persons'
 *                          to be embedded into the unified RAG output.
 *
 * Both modes use the same underlying retrieval strategy
 * (Heart focus → semantic search → empty).
 */
interface PersonContextEnricherInterface
{
    /**
     * Legacy text-output mode.
     *
     * Returns an EnricherResponse with the formatted persons block as text.
     */
    public function enrich(AiPreset $preset, array $context, ?string $target = null): EnricherResponseInterface;

    /**
     * Structured-output mode for integration into RAG pipeline.
     *
     * Returns a RagSection of type 'persons' whose items carry person facts.
     * Returns null when no relevant persons were found.
     */
    public function enrichAsSection(AiPreset $preset, array $context): ?RagSectionInterface;
}

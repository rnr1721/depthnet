<?php

namespace App\Contracts\Agent\Enricher\Rag;

/**
 * Renders RAG data into the text form injected into prompts or shown in UI.
 *
 * The formatter dispatches each section to its type-specific renderer
 * (via RagSectionRendererRegistry) and wraps the result with the outer
 * [RAG CONTEXT — query: ...] / [END RAG CONTEXT] frame.
 *
 * Two render modes for different consumers:
 *   - Individual: one config's RagData, shown as a system message in UI.
 *   - Aggregated: merged AggregatedRagResult, injected into [[rag_context]]
 *                 for the model.
 *
 * Both modes share the same per-section rendering — only the outer frame
 * and whether sections from one or multiple configs are present differ.
 */
interface RagContentFormatterInterface
{
    /**
     * Format one config's payload as a labeled system message.
     *
     * Returns empty string when payload has no sections.
     */
    public function formatIndividual(RagDataInterface $data): string;

    /**
     * Format the merged result for injection into [[rag_context]].
     *
     * Returns empty string when result has no sections.
     */
    public function formatAggregated(AggregatedRagResultInterface $result): string;
}

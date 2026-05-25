<?php

namespace App\Contracts\Agent\Enricher\Rag;

/**
 * Final merged result of the multi-config RAG pipeline.
 *
 * Passed from the aggregator to the formatter. Contains everything
 * needed to render the [[rag_context]] block:
 *   - The union of queries used across all configs (for the header)
 *   - The merged, deduplicated sections in canonical render order
 */
interface AggregatedRagResultInterface
{
    /**
     * Combined queries from all configs, deduplicated, in order of first appearance.
     *
     * @return string[]
     */
    public function getQueries(): array;

    /**
     * Sections in the order they should be rendered.
     * Follows RagSectionType::renderOrder() rather than per-config order.
     *
     * @return RagSectionInterface[]
     */
    public function getSections(): array;

    public function isEmpty(): bool;
}

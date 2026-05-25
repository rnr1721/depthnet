<?php

namespace App\Contracts\Agent\Enricher\Rag;

/**
 * Merges multiple per-config RagData payloads into a single AggregatedRagResult.
 *
 * Aggregation works at the section level:
 *   - Sections of the same type from different configs merge into one
 *   - Items within merged sections are deduplicated by their dedup key
 *   - Item ordering preserves relevance: higher score and higher-priority config win
 *
 * Section render options are inherited from the highest-priority payload
 * that supplied them.
 *
 * The aggregator is the only component aware of the multi-config pipeline —
 * individual RagContextEnricher passes don't know about each other.
 */
interface RagAggregatorServiceInterface
{
    /**
     * @param RagDataInterface[] $payloads
     */
    public function merge(array $payloads): AggregatedRagResultInterface;
}

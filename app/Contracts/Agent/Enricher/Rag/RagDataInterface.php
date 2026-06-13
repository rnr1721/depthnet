<?php

namespace App\Contracts\Agent\Enricher\Rag;

use App\Contracts\Agent\Enricher\EnricherPayloadInterface;

/**
 * Structured payload returned by RagContextEnricher::enrichWithConfig().
 *
 * Carries:
 *   - The queries used for retrieval (so the formatter can show them in the header)
 *   - All sections produced by this config pass
 *   - Config metadata for aggregator ranking
 *
 * The same RagData is used in two ways:
 *   - Passed to RagContentFormatter::formatIndividual() for the UI system message
 *   - Passed to RagAggregator::merge() for cross-config aggregation
 */
interface RagDataInterface extends EnricherPayloadInterface
{
    /**
     * Search queries used in this pass — included in the rendered header.
     *
     * @return string[]
     */
    public function getQueries(): array;

    /**
     * Sections retrieved by this config.
     *
     * @return RagSectionInterface[]
     */
    public function getSections(): array;

    /**
     * ID of the PresetRagConfig that produced this payload.
     */
    public function getSourceConfigId(): int;

    /**
     * Priority for cross-config ranking (lower = higher priority).
     * Typically sourced from PresetRagConfig::sort_order.
     */
    public function getPriority(): int;

    /**
     * True when this payload has no non-empty sections at all.
     */
    public function isEmpty(): bool;
}

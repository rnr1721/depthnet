<?php

namespace App\Services\Agent\Enricher\Rag;

use App\Contracts\Agent\Enricher\Rag\RagDataInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionInterface;

/**
 * Default implementation of RagDataInterface.
 *
 * Carries the structured output of one RagContextEnricher::enrichWithConfig() call.
 */
final class RagData implements RagDataInterface
{
    /**
     * @param string[]              $queries
     * @param RagSectionInterface[] $sections
     */
    public function __construct(
        private readonly array $queries,
        private readonly array $sections,
        private readonly int   $sourceConfigId,
        private readonly int   $priority = 0,
    ) {
    }

    public function getQueries(): array
    {
        return $this->queries;
    }

    public function getSections(): array
    {
        return $this->sections;
    }

    public function getSourceConfigId(): int
    {
        return $this->sourceConfigId;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function isEmpty(): bool
    {
        foreach ($this->sections as $section) {
            if (!$section->isEmpty()) {
                return false;
            }
        }
        return true;
    }
}

<?php

namespace App\Services\Agent\Enricher\Rag;

use App\Contracts\Agent\Enricher\Rag\AggregatedRagResultInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionInterface;

/**
 * Default implementation of AggregatedRagResultInterface.
 */
final class AggregatedRagResult implements AggregatedRagResultInterface
{
    /**
     * @param string[]              $queries
     * @param RagSectionInterface[] $sections
     */
    public function __construct(
        private readonly array $queries,
        private readonly array $sections,
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

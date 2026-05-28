<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagSectionType;
use App\Contracts\Agent\PulseServiceInterface;

/**
 * Renders memory items found via TF-IDF fallback
 * (records that have content but no embedding yet).
 */
final class MemoryKeywordFallbackRenderer extends AbstractMemoryRenderer
{
    public function __construct(PulseServiceInterface $pulse)
    {
        parent::__construct(
            sectionType:       RagSectionType::MemoryKeywordFallback->value,
            label:             '[KEYWORD MEMORY — no embedding yet]',
            useCompositeScore: false,
            pulse:             $pulse,
        );
    }
}

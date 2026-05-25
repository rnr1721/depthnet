<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagSectionType;

/**
 * Renders memory items found via TF-IDF fallback
 * (records that have content but no embedding yet).
 */
final class MemoryKeywordFallbackRenderer extends AbstractMemoryRenderer
{
    public function __construct()
    {
        parent::__construct(
            sectionType:       RagSectionType::MemoryKeywordFallback->value,
            label:             '[KEYWORD MEMORY — no embedding yet]',
            useCompositeScore: false,
        );
    }
}

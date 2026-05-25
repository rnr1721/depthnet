<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagSectionType;

final class MemoryKeywordRenderer extends AbstractMemoryRenderer
{
    public function __construct()
    {
        parent::__construct(
            sectionType:       RagSectionType::MemoryKeyword->value,
            label:             '[KEYWORD MEMORY]',
            useCompositeScore: true,
        );
    }
}

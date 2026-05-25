<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagSectionType;

final class MemorySemanticRenderer extends AbstractMemoryRenderer
{
    public function __construct()
    {
        parent::__construct(
            sectionType:       RagSectionType::MemorySemantic->value,
            label:             '[SEMANTIC MEMORY]',
            useCompositeScore: true,
        );
    }
}

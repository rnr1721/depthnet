<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagSectionType;

final class MemorySemanticAssociativeRenderer extends AbstractMemoryRenderer
{
    public function __construct()
    {
        parent::__construct(
            sectionType:       RagSectionType::MemorySemanticAssociative->value,
            label:             '[SEMANTIC ASSOCIATIVE MEMORY]',
            useCompositeScore: true,
        );
    }
}

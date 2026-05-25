<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagSectionType;

final class MemoryAssociativeRenderer extends AbstractMemoryRenderer
{
    public function __construct()
    {
        parent::__construct(
            sectionType:       RagSectionType::MemoryAssociative->value,
            label:             '[ASSOCIATIVE MEMORY]',
            useCompositeScore: true,
        );
    }
}

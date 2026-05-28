<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagSectionType;
use App\Contracts\Agent\PulseServiceInterface;

final class MemoryAssociativeRenderer extends AbstractMemoryRenderer
{
    public function __construct(PulseServiceInterface $pulse)
    {
        parent::__construct(
            sectionType:       RagSectionType::MemoryAssociative->value,
            label:             '[ASSOCIATIVE MEMORY]',
            useCompositeScore: true,
            pulse:             $pulse,
        );
    }
}

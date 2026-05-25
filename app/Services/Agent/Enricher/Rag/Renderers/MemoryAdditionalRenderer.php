<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagSectionType;

/**
 * Renders flat memory results from supplementary queries.
 *
 * The default label is "[ADDITIONAL MEMORY]". When the enricher knows
 * multiple queries were used, it passes "[MULTI-QUERY MEMORY]" as the
 * section's explicit label override — the formatter respects that.
 */
final class MemoryAdditionalRenderer extends AbstractMemoryRenderer
{
    public function __construct()
    {
        parent::__construct(
            sectionType:       RagSectionType::MemoryAdditional->value,
            label:             '[ADDITIONAL MEMORY]',
            useCompositeScore: false,
        );
    }
}

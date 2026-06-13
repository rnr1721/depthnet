<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagSectionInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionType;

/**
 * Renders ontology snapshots.
 *
 * Each item's content is already a formatted snapshot string produced by
 * OntologyService::getSnapshot(). The renderer simply emits them one per line block.
 */
final class OntologySectionRenderer extends AbstractSectionRenderer
{
    public function supports(): string
    {
        return RagSectionType::Ontology->value;
    }

    public function defaultLabel(): string
    {
        return '[ONTOLOGY CONTEXT]';
    }

    public function render(RagSectionInterface $section): array
    {
        $lines = [];

        foreach ($section->getItems() as $item) {
            $lines[] = $item->getContent();
        }

        return $lines;
    }
}

<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagSectionInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionType;

/**
 * Renders persons facts grouped by person name.
 *
 * Person item metadata:
 *   - 'person_name' : string  — used for grouping
 *   - 'fact_id'     : int     — used as the fact identifier in output
 *
 * Output:
 *   Eugeny:
 *     #12 Developer of DepthNet
 *     #13 Lives in Kharkiv
 *
 *   Adaliya:
 *     #25 Runs on DeepSeek backend
 */
final class PersonsSectionRenderer extends AbstractSectionRenderer
{
    public function supports(): string
    {
        return RagSectionType::Persons->value;
    }

    public function defaultLabel(): string
    {
        return '[PERSONS CONTEXT]';
    }

    public function render(RagSectionInterface $section): array
    {
        // Group items by person_name, preserving first-appearance order
        $byPerson = [];

        foreach ($section->getItems() as $item) {
            $meta = $item->getMetadata();
            $name = $meta['person_name'] ?? null;

            if (empty($name) || empty(trim($name))) {
                continue;
            }

            $byPerson[$name][] = $item;
        }

        if (empty($byPerson)) {
            return [];
        }

        $lines = [];

        foreach ($byPerson as $name => $items) {
            $lines[] = "{$name}:";

            foreach ($items as $item) {
                $factId = $item->getMetadata()['fact_id'] ?? '—';
                $lines[] = "  #{$factId} {$item->getContent()}";
            }

            $lines[] = '';
        }

        // Drop trailing empty line — formatter handles spacing
        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }
}

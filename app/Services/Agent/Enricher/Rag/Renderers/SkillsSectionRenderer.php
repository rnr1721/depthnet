<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagSectionInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionType;

/**
 * Renders skill items.
 *
 * Skill item metadata:
 *   - 'skill_number'        : int
 *   - 'item_number'         : int
 *   - 'skill_title'         : string
 *   - 'similarity_percent'  : numeric
 *
 * Output line format:
 *   Skill #3 "Title" — item 3.5 (87%): Content text...
 */
final class SkillsSectionRenderer extends AbstractSectionRenderer
{
    public function supports(): string
    {
        return RagSectionType::Skills->value;
    }

    public function defaultLabel(): string
    {
        return '[RELEVANT SKILLS]';
    }

    public function render(RagSectionInterface $section): array
    {
        $options         = $section->getRenderOptions();
        $maxContentLimit = (int) $this->option($options, 'max_content_limit', self::DEFAULT_CONTENT_LIMIT);

        $lines = [];

        foreach ($section->getItems() as $item) {
            $meta = $item->getMetadata();

            $lines[] = sprintf(
                'Skill #%d "%s" — item %d.%d (%s%%): %s',
                $meta['skill_number'] ?? 0,
                $meta['skill_title'] ?? '',
                $meta['skill_number'] ?? 0,
                $meta['item_number'] ?? 0,
                $meta['similarity_percent'] ?? 0,
                mb_substr($item->getContent(), 0, $maxContentLimit),
            );
        }

        return $lines;
    }
}

<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagItemInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionInterface;
use App\Contracts\Agent\PulseServiceInterface;
use App\Services\Agent\Enricher\Rag\Renderers\Concerns\FormatsPulseLabel;

/**
 * Shared rendering for all memory section variants.
 *
 * Memory items expect metadata:
 *   - 'document' or 'memory'  : the memory model (must implement getTextContent + getCreatedAt)
 *   - 'composite_score' (opt) : score from associative composite calculation
 *   - 'similarity'            : fallback score
 *
 * Variants only differ in default label and whether they use composite_score —
 * concrete renderers configure these via constructor.
 *
 * Pulse coordinate labels (e.g. "day 89 pulse 605") are appended to the
 * date block when the section's render options enable show_pulse_date.
 * The label is derived from the memory's getCreatedAt() — the moment the
 * insight was crystallised in the agent's life — via PulseService.
 */
abstract class AbstractMemoryRenderer extends AbstractSectionRenderer
{
    use FormatsPulseLabel;

    public function __construct(
        protected readonly string                $sectionType,
        protected readonly string                $label,
        protected readonly bool                  $useCompositeScore,
        protected readonly PulseServiceInterface $pulse,
    ) {
    }

    public function supports(): string
    {
        return $this->sectionType;
    }

    public function defaultLabel(): string
    {
        return $this->label;
    }

    public function render(RagSectionInterface $section): array
    {
        $options          = $section->getRenderOptions();
        $maxContentLimit  = (int) $this->option($options, 'max_content_limit', self::DEFAULT_CONTENT_LIMIT);
        $showRelativeDate = (bool) $this->option($options, 'show_relative_date', false);

        $lines = [];
        $num   = 1;

        foreach ($section->getItems() as $item) {
            $lines[] = $this->renderItem($num++, $item, $maxContentLimit, $showRelativeDate, $options);
        }

        return $lines;
    }

    private function renderItem(
        int              $num,
        RagItemInterface $item,
        int              $maxContentLimit,
        bool             $showRelativeDate,
        array            $options,
    ): string {
        $meta   = $item->getMetadata();
        $memory = $meta['document'] ?? $meta['memory'] ?? null;

        if ($memory === null) {
            // Fallback: use content directly if no model in metadata.
            // No date anchor is available, so pulse label is skipped.
            $score = round(($item->getScore() ?? 0) * 100, 1);
            return sprintf('%d. [— | %s%%] %s', $num, $score, mb_substr($item->getContent(), 0, $maxContentLimit));
        }

        $rawScore = $this->useCompositeScore
            ? ($meta['composite_score'] ?? $item->getScore() ?? 0)
            : ($item->getScore() ?? 0);

        $score   = round($rawScore * 100, 1);
        $content = mb_substr($memory->getTextContent(), 0, $maxContentLimit);
        $created = $memory->getCreatedAt();
        $dateStr = $created->format('Y-m-d');

        if ($showRelativeDate) {
            $dateStr .= ' (' . $this->formatRelativeDate($created) . ')';
        }

        // Pulse label is appended as an additional bracketed segment so
        // it composes cleanly with the absolute/relative date block.
        // Layout: [date | (rel) | pulse | score%] content
        $pulseLabel = $this->formatPulseLabel($created, $options);
        $pulsePart  = $pulseLabel !== null ? ' | ' . $pulseLabel : '';

        return sprintf('%d. [%s%s | %s%%] %s', $num, $dateStr, $pulsePart, $score, $content);
    }
}

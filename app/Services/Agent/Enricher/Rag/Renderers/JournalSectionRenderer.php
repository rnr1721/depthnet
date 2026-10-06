<?php

namespace App\Services\Agent\Enricher\Rag\Renderers;

use App\Contracts\Agent\Enricher\Rag\RagItemInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionType;
use App\Contracts\Agent\PulseServiceInterface;
use App\Services\Agent\Enricher\Rag\Renderers\Concerns\FormatsPulseLabel;

/**
 * Renders journal entries with anchor/neighbour distinction.
 *
 * Journal items must already include both anchors and any neighbour
 * entries (fetched by RagContextEnricher) sorted by recorded_at.
 * The renderer doesn't query — it just formats.
 *
 * Journal item metadata:
 *   - 'entry'     : JournalEntry model (must expose id, recorded_at, type,
 *                                       summary, outcome, optional details)
 *   - 'is_anchor' : bool  — distinguishes ★ anchors from [ctx] neighbours
 *
 * Output mixes anchors (with ★, type, outcome) and ctx-rows in chronological order.
 * When the section's render options enable show_pulse_date, each row gains a
 * compact "day N pulse M" coordinate derived from the entry's recorded_at —
 * giving each event a unique unrepeatable position in the agent's life.
 */
final class JournalSectionRenderer extends AbstractSectionRenderer
{
    use FormatsPulseLabel;

    public function __construct(
        protected readonly PulseServiceInterface $pulse,
    ) {
    }

    public function supports(): string
    {
        return RagSectionType::Journal->value;
    }

    public function defaultLabel(): string
    {
        return '[RELEVANT JOURNAL ENTRIES]';
    }

    public function render(RagSectionInterface $section): array
    {
        $options          = $section->getRenderOptions();
        $maxContentLimit  = (int) $this->option($options, 'max_content_limit', self::DEFAULT_CONTENT_LIMIT);
        $showRelativeDate = (bool) $this->option($options, 'show_relative_date', false);

        // Items are expected to be pre-sorted chronologically by the enricher,
        // but we sort defensively in case aggregation reorders them.
        $items = $section->getItems();
        usort(
            $items,
            fn (RagItemInterface $a, RagItemInterface $b) =>
            ($a->getMetadata()['recorded_at_iso'] ?? null)
            <=>
            ($b->getMetadata()['recorded_at_iso'] ?? null)
        );

        $lines = [];

        foreach ($items as $item) {
            $meta     = $item->getMetadata();
            $entryId  = $meta['entry_id'] ?? null;
            $isAnchor = (bool) ($meta['is_anchor'] ?? false);

            if ($entryId === null) {
                continue;
            }

            $recordedAt = isset($meta['recorded_at_iso'])
                ? \Carbon\Carbon::parse($meta['recorded_at_iso'])
                : null;

            if ($recordedAt === null) {
                continue;
            }

            $date = $recordedAt->format('Y-m-d H:i');
            if ($showRelativeDate) {
                $date .= ' (' . $this->formatRelativeDate($recordedAt) . ')';
            }

            $pulseLabel = $this->formatPulseLabel($recordedAt, $options);
            $pulsePart  = $pulseLabel !== null ? " [{$pulseLabel}]" : '';

            $type    = $meta['type'] ?? '';
            $summary = $meta['summary'] ?? $item->getContent();

            if ($isAnchor) {
                $outcome = !empty($meta['outcome']) ? " [{$meta['outcome']}]" : '';
                $lines[] = sprintf(
                    '★ #{%d} [%s]%s [%s]%s %s',
                    $entryId,
                    $date,
                    $pulsePart,
                    $type,
                    $outcome,
                    mb_substr($summary, 0, $maxContentLimit),
                );
            } else {
                $lines[] = sprintf(
                    '  [ctx] #{%d} [%s]%s [%s] %s',
                    $entryId,
                    $date,
                    $pulsePart,
                    $type,
                    mb_substr($summary, 0, $maxContentLimit),
                );
            }
        }

        return $lines;
    }
}

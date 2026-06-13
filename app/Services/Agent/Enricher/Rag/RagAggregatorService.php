<?php

namespace App\Services\Agent\Enricher\Rag;

use App\Contracts\Agent\Enricher\Rag\AggregatedRagResultInterface;
use App\Contracts\Agent\Enricher\Rag\RagAggregatorServiceInterface;
use App\Contracts\Agent\Enricher\Rag\RagDataInterface;
use App\Contracts\Agent\Enricher\Rag\RagItemInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionInterface;

/**
 * Default RAG aggregator.
 *
 * Strategy:
 *   1. Sort incoming payloads by priority (lower = higher priority)
 *      so that during dedup the higher-priority config's chunks survive.
 *   2. Group sections from all payloads by type.
 *   3. Within each type group, flatten items, deduplicate by dedup key
 *      (first occurrence wins thanks to step 1).
 *   4. Inside each merged section, sort items by score desc
 *      (items without scores keep their original relative order).
 *   5. Render options and label come from the highest-priority section
 *      of that type.
 */
final class RagAggregatorService implements RagAggregatorServiceInterface
{
    public function merge(array $payloads): AggregatedRagResultInterface
    {
        if (empty($payloads)) {
            return new AggregatedRagResult([], []);
        }

        // 1. Priority order — lower priority wins ties during dedup
        $sortedPayloads = $payloads;
        usort(
            $sortedPayloads,
            fn (RagDataInterface $a, RagDataInterface $b) => $a->getPriority() <=> $b->getPriority(),
        );

        // 2. Group sections by type, collecting queries on the way
        $allQueries     = [];
        $sectionsByType = [];

        foreach ($sortedPayloads as $payload) {
            foreach ($payload->getQueries() as $q) {
                $allQueries[$q] = true;
            }

            foreach ($payload->getSections() as $section) {
                if ($section->isEmpty()) {
                    continue;
                }

                $sectionsByType[$section->getType()][] = $section;
            }
        }

        // 3-5. Merge sections of the same type
        $mergedSections = [];

        foreach ($sectionsByType as $type => $sections) {
            $merged = $this->mergeSectionsOfType($sections);

            if (!$merged->isEmpty()) {
                $mergedSections[] = $merged;
            }
        }

        return new AggregatedRagResult(
            queries:  array_keys($allQueries),
            sections: $mergedSections,
        );
    }

    /**
     * Merge multiple sections of the same type into one.
     *
     * @param RagSectionInterface[] $sections  Same-type sections, ordered by config priority
     */
    private function mergeSectionsOfType(array $sections): RagSectionInterface
    {
        // The first section (highest priority) provides label and render options
        $primary = $sections[0];

        $seen        = [];
        $mergedItems = [];

        foreach ($sections as $section) {
            foreach ($section->getItems() as $item) {
                $key = $item->getDedupKey();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $mergedItems[] = $item;
            }
        }

        // Sort by score desc — items without a score keep their original order
        // relative to each other (stable sort via index fallback)
        usort($mergedItems, function (RagItemInterface $a, RagItemInterface $b) use ($mergedItems) {
            $sa = $a->getScore();
            $sb = $b->getScore();

            if ($sa === null && $sb === null) {
                return 0;
            }
            if ($sa === null) {
                return 1;  // null goes last
            }
            if ($sb === null) {
                return -1;
            }

            return $sb <=> $sa; // desc
        });

        return new RagSection(
            type:          $primary->getType(),
            items:         $mergedItems,
            label:         $primary->getLabel(),
            renderOptions: $primary->getRenderOptions(),
        );
    }
}

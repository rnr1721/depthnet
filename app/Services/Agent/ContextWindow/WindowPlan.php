<?php

namespace App\Services\Agent\ContextWindow;

/**
 * What the planner decided to put in the window.
 *
 * $rows are ascending by id and include the pinned head (if any). When
 * $pinnedHead is set, $elidedCycles > 0 cycles of the current episode lie
 * between it and the rest of the window; the selector annotates the head so
 * the model knows there is a gap. $outlined is how many older episodes went
 * into the outline row.
 */
final class WindowPlan
{
    /**
     * @param object[] $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly ?object $pinnedHead,
        public readonly int $elidedCycles,
        public readonly int $spent,
        public readonly int $budget,
        public readonly int $episodesTaken,
        public readonly bool $overflowUsed,
        public readonly int $outlined = 0,
    ) {
    }
}

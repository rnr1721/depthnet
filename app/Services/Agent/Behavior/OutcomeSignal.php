<?php

namespace App\Services\Agent\Behavior;

/**
 * OutcomeSignal — one event-outcome of a completed thinking cycle, in the terms
 * ABS rewards.
 *
 * This is the bridge between AgentActionsHandler (which already computes $spoke /
 * $committed / $created / task-left-IN_PROGRESS / stall) and the ABS credit
 * step. ABS does NOT recompute outcomes — it receives this DTO, built from
 * signals the handler has in hand, and turns `value` into fitness via credit
 * assignment.
 *
 * `value` is the reward magnitude for this cycle's outcome:
 *   positive — a productive exit was reached (exit condition met)
 *   zero     — neutral / no outcome this cycle (most cycles)
 *   negative — a failure outcome (stall guard fired, task failed)
 *
 * `kind` is a short label for logs and later analysis — never used in the math,
 * only `value` is. Keeping the label separate from the number means we can
 * retune what each outcome is WORTH (the architect's formalized narrative —
 * acknowledged Goodhart) without touching the detection that produced it.
 *
 * HONEST BOUNDARY: `value` here is the architect's formalized narrative of
 * success, not an objective fact. The DETECTION (did the task leave IN_PROGRESS?)
 * is code-checked and not fakeable; what that detection is WORTH is a human
 * choice. Phase 1 measures whether selection works as a mechanism — not whether
 * it correlates with external reality, which is not under ABS's feet in phase 1.
 */
final class OutcomeSignal
{
    public function __construct(
        public readonly float  $value,
        public readonly string $kind,
    ) {
    }

    /** A productive, positive outcome (e.g. planner committed, task done). */
    public static function positive(string $kind, float $value): self
    {
        return new self(max(0.0, $value), $kind);
    }

    /** A failure outcome (e.g. stall guard fired, task failed). */
    public static function negative(string $kind, float $value): self
    {
        return new self(-abs($value), $kind);
    }

    /** No outcome worth crediting this cycle. */
    public static function none(): self
    {
        return new self(0.0, 'none');
    }

    public function isCreditable(): bool
    {
        return $this->value !== 0.0;
    }
}

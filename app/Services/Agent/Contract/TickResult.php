<?php

namespace App\Services\Agent\Contract;

/**
 * TickResult — what happened in one engine tick over one preset.
 *
 * Purely diagnostic: the tick driver logs it, the UI can surface it. Each
 * contract evaluated lands in exactly one bucket (by name). `skipped` is set
 * when the whole tick was coalesced away (called sooner than min interval).
 */
final class TickResult
{
    /**
     * @param array<int,string> $fired         rising edge → action ran
     * @param array<int,string> $cleared       falling edge → flag dropped
     * @param array<int,string> $held          still triggered, no edge
     * @param array<int,string> $idle          not triggered
     * @param array<int,string> $suspended     skipped this tick via suspend_when
     * @param array<int,string> $unsatisfiable missing reader / state vector
     */
    public function __construct(
        public readonly int   $evaluated,
        public readonly int   $dtSeconds,
        public readonly bool  $skipped,
        public readonly array $fired = [],
        public readonly array $cleared = [],
        public readonly array $held = [],
        public readonly array $idle = [],
        public readonly array $suspended = [],
        public readonly array $unsatisfiable = [],
    ) {
    }

    public static function skipped(int $dtSeconds): self
    {
        return new self(evaluated: 0, dtSeconds: $dtSeconds, skipped: true);
    }

    /** Compact one-line summary for logs. */
    public function summary(): string
    {
        if ($this->skipped) {
            return "tick skipped (dt={$this->dtSeconds}s < min interval)";
        }

        return sprintf(
            'tick: %d evaluated, dt=%ds | fired=%d cleared=%d held=%d idle=%d suspended=%d unsat=%d',
            $this->evaluated,
            $this->dtSeconds,
            count($this->fired),
            count($this->cleared),
            count($this->held),
            count($this->idle),
            count($this->suspended),
            count($this->unsatisfiable),
        );
    }
}

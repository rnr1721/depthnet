<?php

namespace App\Services\Agent\Behavior;

use App\Models\BehaviorPattern;

/**
 * SelectionResult — what PatternSelector decided for one cycle.
 *
 * Path A (all-who-were-ready learn): "being ready to act" and "getting the turn"
 * are different things. A pattern whose trigger fired was PART of this cycle —
 * it shaped what the agent was, even if another pattern led the behavior. So it
 * earns a share of the outcome's credit. Recording only the winner was the
 * design flaw that locked the population: a losing-but-eligible pattern never
 * learned, so it could never overtake the early priority favourite. Now every
 * triggered pattern is recorded and credited; one still DOMINATES (leads
 * behavior), but all who were ready LEARN.
 *
 * Fields:
 *   triggered   — ALL active patterns whose trigger fired this cycle (the
 *                 eligible set). Each is recorded as an activation; each is
 *                 creditable.
 *   winner      — the highest-scoring member of `triggered`, or null if nothing
 *                 triggered. The scored competitor that leads behavior UNLESS a
 *                 forced pattern overrides.
 *   forced      — an immune pattern whose quota is due, or null. When present it
 *                 SEIZES behavior (the reservation is lived, not logged) and is
 *                 recorded with reason='forced' — NOT creditable (presence does
 *                 not learn from outcomes). It does NOT remove the eligible set:
 *                 other patterns that triggered this cycle still learn.
 *
 * Activation recording (in the coordinator), per pattern this cycle:
 *   - the dominant (forced ?? winner) -> reason 'forced' (if forced) else 'trigger'
 *   - every other triggered pattern   -> reason 'eligible'
 *   credit assignment pays 'trigger' + 'eligible', skips 'forced'.
 */
final class SelectionResult
{
    /**
     * @param BehaviorPattern[] $triggered All patterns whose trigger fired.
     */
    public function __construct(
        public readonly array            $triggered,
        public readonly ?BehaviorPattern $winner,
        public readonly ?float           $winnerScore,
        public readonly ?BehaviorPattern $forced,
        public readonly ?string          $pulseLabel,
    ) {
    }

    public static function empty(): self
    {
        return new self([], null, null, null, null);
    }

    public function triggeredCount(): int
    {
        return count($this->triggered);
    }

    public function hasActivation(): bool
    {
        return !empty($this->triggered) || $this->forced !== null;
    }

    /**
     * The pattern that actually LEADS this cycle's behavior: forced overrides
     * winner. Null only when nothing triggered and no quota was due.
     */
    public function dominant(): ?BehaviorPattern
    {
        return $this->forced ?? $this->winner;
    }

    /** Whether the dominant pattern is a forced (quota) activation. */
    public function isForced(): bool
    {
        return $this->forced !== null;
    }

    /** Activation reason for the dominant pattern. */
    public function dominantReason(): string
    {
        return $this->isForced() ? 'forced' : 'trigger';
    }

    /**
     * The eligible patterns that are NOT the dominant one — recorded with
     * reason='eligible'. These triggered but did not lead behavior; they still
     * learn.
     *
     * @return BehaviorPattern[]
     */
    public function eligibleNonDominant(): array
    {
        $dominant = $this->dominant();
        $dominantId = $dominant?->id;

        return array_values(array_filter(
            $this->triggered,
            fn (BehaviorPattern $p) => $p->id !== $dominantId
        ));
    }
}

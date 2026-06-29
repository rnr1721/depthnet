<?php

namespace App\Contracts\Agent\Behavior;

use App\Models\AiPreset;

/**
 * BehaviorRuntimeServiceInterface — ABS engine hot-path state.
 *
 * Owns the cycle_seq axis, the activation log, and atomic fitness writes.
 * Implementations write per-cycle columns through the query builder directly
 * (not Eloquent), mirroring ContractRuntimeService's discipline.
 */
interface BehaviorRuntimeServiceInterface
{
    /** Atomically advance and return the preset's completed-cycle counter. */
    public function nextCycleSeq(AiPreset $preset): int;

    /** Read the current cycle_seq without advancing (0 if ABS never ran here). */
    public function currentCycleSeq(AiPreset $preset): int;

    /**
     * Record that a pattern activated this cycle: append an eligibility-trace row
     * and bump the pattern's activation bookkeeping atomically.
     *
     * @param string $reason 'trigger' (won competition) | 'forced' (quota)
     */
    public function recordActivation(
        AiPreset $preset,
        int $patternId,
        int $cycleSeq,
        string $reason = 'trigger',
        ?string $pulseLabel = null,
    ): void;

    /**
     * Uncredited, creditable activations within the credit horizon, newest first.
     * Forced activations are excluded — immune patterns do not learn from outcomes.
     *
     * @return array<int,object> rows: {id, pattern_id, cycle_seq}
     */
    public function uncreditedWithin(AiPreset $preset, int $currentCycleSeq, int $horizon): array;

    /** Mark an activation row paid, recording the credit applied (audit). */
    public function markCredited(int $activationId, float $creditApplied): void;

    /** Apply a fitness delta to a pattern atomically. Positive or negative. */
    public function addFitness(int $patternId, float $delta): void;

    /**
     * Multiplicatively decay fitness for patterns idle at/before the seq floor.
     * Returns the number of patterns affected.
     */
    public function decayInactive(AiPreset $preset, int $seqFloor, float $factor): int;
}

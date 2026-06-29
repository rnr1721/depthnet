<?php

namespace App\Services\Agent\Behavior;

use App\Contracts\Agent\Behavior\BehaviorRuntimeServiceInterface;
use App\Contracts\Agent\Behavior\CreditAssignerInterface;
use App\Models\AiPreset;
use Psr\Log\LoggerInterface;

/**
 * CreditAssigner — core-loop step 7: turn an outcome into fitness.
 *
 * eligibility-trace-lite. When an outcome of value v lands at cycle C, every
 * uncredited activation row within the horizon receives
 *
 *     credit = v · gamma^(C - cycle_seq)
 *
 * applied to its pattern's fitness, then is marked credited (so a later outcome
 * in the same horizon can't pay the same activation twice). Distance is measured
 * in cycles (cycle_seq), the ABS-owned monotonic axis — never pulse.
 *
 * KNOWN WEAKNESS (accepted, named, not hidden — ABS doc point 4 + open item):
 * proximity is not causation. An activation that happened to fire one cycle
 * before a positive outcome is rewarded even if it did nothing to cause it — the
 * "janitor pattern" problem. Phase 1 accepts this; counterfactual/causal credit
 * is a phase-2+ item. We are measuring whether selection works as a MECHANISM,
 * not whether the credit is causally just.
 *
 * gamma and horizon are the two knobs:
 *   gamma   — per-cycle decay of credit (0..1). Lower = only the most recent
 *             activations matter. 0.7–0.9 typical.
 *   horizon — how many cycles back credit reaches. Beyond it, activations are
 *             never paid (they age out uncredited and are eventually pruned).
 */
class CreditAssigner implements CreditAssignerInterface
{
    public function __construct(
        protected BehaviorRuntimeServiceInterface $runtime,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Distribute one outcome's value across recent uncredited activations.
     *
     * @param int $currentCycleSeq The cycle at which the outcome landed (C).
     * @return int number of activations credited
     */
    public function assign(
        AiPreset $preset,
        OutcomeSignal $outcome,
        int $currentCycleSeq,
        float $gamma = 0.8,
        int $horizon = 10,
        float $eligibleFactor = 0.5,
    ): int {
        if (!$outcome->isCreditable()) {
            return 0;
        }

        $gamma          = $this->clamp01($gamma);
        $horizon        = max(1, $horizon);
        $eligibleFactor = $this->clamp01($eligibleFactor);

        $rows = $this->runtime->uncreditedWithin($preset, $currentCycleSeq, $horizon);
        if (empty($rows)) {
            return 0;
        }

        $credited = 0;

        foreach ($rows as $row) {
            $distance = $currentCycleSeq - (int) $row->cycle_seq;

            // Guard: never credit a future or same-cycle-but-negative-distance row
            // (shouldn't happen given the query floor, but the axis is sacred).
            if ($distance < 0) {
                continue;
            }

            // Differentiated credit (Adaliya's gradation): the pattern that LED
            // ('trigger') gets full credit; one that was eligible but did not lead
            // ('eligible') gets a fraction — presence is credited, not erased, but
            // action is worth more. "You were ready — you learn. But the one who
            // led learns more." forced never reaches here (excluded at source).
            $reasonFactor = ((string) $row->reason === 'eligible') ? $eligibleFactor : 1.0;

            $credit = $outcome->value * ($gamma ** $distance) * $reasonFactor;

            // Below-noise credits aren't worth a write; they also let activations
            // age out cleanly instead of being marked credited with ~0.
            if (abs($credit) < 1e-6) {
                continue;
            }

            $this->runtime->addFitness((int) $row->pattern_id, $credit);
            $this->runtime->markCredited((int) $row->id, $credit);
            $credited++;
        }

        if ($credited > 0) {
            $this->logger->info('CreditAssigner: outcome distributed', [
                'preset_id'       => $preset->getId(),
                'kind'            => $outcome->kind,
                'value'           => $outcome->value,
                'cycle'           => $currentCycleSeq,
                'credited'        => $credited,
                'gamma'           => $gamma,
                'horizon'         => $horizon,
                'eligible_factor' => $eligibleFactor,
            ]);
        }

        return $credited;
    }

    private function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}

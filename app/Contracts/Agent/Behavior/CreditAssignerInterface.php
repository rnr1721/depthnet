<?php

namespace App\Contracts\Agent\Behavior;

use App\Models\AiPreset;
use App\Services\Agent\Behavior\OutcomeSignal;

/**
 * CreditAssignerInterface — core-loop step 7: turn an outcome into fitness via
 * eligibility-trace-lite (credit = value · gamma^cycle_distance).
 */
interface CreditAssignerInterface
{
    /**
     * Distribute one outcome's value across recent uncredited activations.
     *
     * @param int $currentCycleSeq The cycle at which the outcome landed.
     * @return int number of activations credited
     */
    public function assign(
        AiPreset $preset,
        OutcomeSignal $outcome,
        int $currentCycleSeq,
        float $gamma = 0.8,
        int $horizon = 10,
        float $eligibleFactor = 0.5,
    ): int;
}

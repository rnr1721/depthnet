<?php

namespace App\Contracts\Agent\Behavior;

use App\Models\AiPreset;
use App\Services\Agent\Behavior\SelectionResult;

/**
 * PatternSelectorInterface — core-loop steps 2–4: trigger activation,
 * competition, and single-dominant selection (with forced-activation quota).
 */
interface PatternSelectorInterface
{
    /**
     * Run selection for this cycle.
     *
     * @param int $cycleSeq The current (already-advanced) preset cycle_seq.
     */
    public function select(AiPreset $preset, int $cycleSeq): SelectionResult;
}

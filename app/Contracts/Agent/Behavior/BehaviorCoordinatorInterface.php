<?php

namespace App\Contracts\Agent\Behavior;

use App\Models\AiPreset;
use App\Services\Agent\Behavior\OutcomeSignal;
use App\Services\Agent\Behavior\SelectionResult;

/**
 * BehaviorCoordinatorInterface — the single public seam of ABS.
 *
 * This is the type Agent and AgentActionsHandler depend on (optionally, nullable
 * — ABS is a bolt-on). Two hooks bracket a thinking cycle: openCycle before
 * generation, closeCycle after turn detection.
 */
interface BehaviorCoordinatorInterface
{
    /** Whether ABS is active for this preset. Gates both hooks. */
    public function isActive(AiPreset $preset): bool;

    /**
     * Advance the cycle, select the dominant pattern, record its activation.
     * Returns the selection so the caller can expose the winner's behavior, or
     * null when ABS is inactive for this preset.
     */
    public function openCycle(AiPreset $preset): ?SelectionResult;

    /** Apply credit assignment for this cycle's outcome. No-op when inactive. */
    public function closeCycle(AiPreset $preset, OutcomeSignal $outcome): void;

    /** Whether this preset had an active task at the previous cycle's close. */
    public function hadActiveTaskLastCycle(AiPreset $preset): bool;

    /** Remember whether this preset had an active task this cycle. */
    public function rememberActiveTask(AiPreset $preset, bool $had): void;
}

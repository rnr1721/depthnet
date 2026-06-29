<?php

namespace App\Services\Agent\Behavior;

use App\Contracts\Agent\Behavior\BehaviorCoordinatorInterface;
use App\Contracts\Agent\Behavior\BehaviorRuntimeServiceInterface;
use App\Contracts\Agent\Behavior\CreditAssignerInterface;
use App\Contracts\Agent\Behavior\PatternSelectorInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Models\AiPreset;
use Psr\Log\LoggerInterface;

/**
 * BehaviorCoordinator — the single public face of ABS for the rest of the agent.
 *
 * Two hooks bracket a thinking cycle:
 *
 *   openCycle()  — called from Agent::setupPresetEnvironment(), BEFORE generation.
 *                  Advances cycle_seq, runs the selector (steps 2–4), records the
 *                  resulting activation(s), and exposes the winner's behavior to
 *                  the speaking pass (phase 1: via a placeholder; enacting through
 *                  plugins/handoff is a later step). Returns the SelectionResult.
 *
 *   closeCycle() — called from AgentActionsHandler, AFTER determineTurnNeed.
 *                  Runs credit assignment (step 7) for this cycle's outcome
 *                  against the activations openCycle recorded.
 *
 * The seq is advanced ONCE per cycle, in openCycle, and reused by closeCycle —
 * both halves of the cycle share the same cycle_seq. We stash it in plugin
 * metadata between the two hooks (read-once), the same discipline ReflectPlugin
 * and memo use, because Agent and AgentActionsHandler are separate objects in
 * the same cycle and don't share instance state.
 *
 * isActive() gates everything: ABS is a bolt-on. A preset that hasn't opted in
 * pays nothing — both hooks early-return.
 */
class BehaviorCoordinator implements BehaviorCoordinatorInterface
{
    private const META_PLUGIN   = 'behavior';
    private const META_CYCLE_SEQ = 'current_cycle_seq';
    private const META_ENABLED   = 'enabled';
    private const META_HAD_TASK  = 'had_active_task';

    public function __construct(
        protected BehaviorRuntimeServiceInterface $runtime,
        protected PatternSelectorInterface $selector,
        protected CreditAssignerInterface $credit,
        protected PluginMetadataServiceInterface $meta,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Whether ABS is active for this preset. Phase 1: a metadata flag under the
     * 'behavior' plugin namespace, set when the operator opts the preset into
     * ABS. Cheap boolean read; gates both hooks.
     */
    public function isActive(AiPreset $preset): bool
    {
        return (bool) $this->meta->get($preset, self::META_PLUGIN, self::META_ENABLED, false);
    }

    // ── Open: select + activate (before generation) ──────────────────────────

    /**
     * Advance the cycle, select the dominant pattern, record activations.
     * Returns the selection so the caller can expose the winner's behavior.
     */
    public function openCycle(AiPreset $preset): ?SelectionResult
    {
        if (!$this->isActive($preset)) {
            return null;
        }

        // One advance per cycle; closeCycle reuses this same value.
        $cycleSeq = $this->runtime->nextCycleSeq($preset);
        $this->meta->set($preset, self::META_PLUGIN, self::META_CYCLE_SEQ, $cycleSeq);

        $selection = $this->selector->select($preset, $cycleSeq);

        // Path A: record an activation for EVERY pattern that was part of this
        // cycle, so all who were ready can learn from the outcome — not only the
        // one that led behavior. Recording only the winner was the lock that
        // starved eligible-but-losing patterns.
        //
        //   dominant (forced ?? winner) → 'forced' (immune, NOT credited) or
        //                                 'trigger' (leads behavior, credited)
        //   every other triggered       → 'eligible' (was ready, credited)
        //
        // One pattern, one activation row per cycle: the dominant is recorded with
        // its dominant reason and is excluded from the eligible list, so it is
        // never double-recorded.
        $dominant = $selection->dominant();
        if ($dominant !== null) {
            $this->runtime->recordActivation(
                $preset,
                $dominant->id,
                $cycleSeq,
                $selection->dominantReason(),
                $selection->pulseLabel,
            );
        }

        foreach ($selection->eligibleNonDominant() as $p) {
            $this->runtime->recordActivation(
                $preset,
                $p->id,
                $cycleSeq,
                'eligible',
                $selection->pulseLabel,
            );
        }

        return $selection;
    }

    // ── Close: credit (after turn detection) ─────────────────────────────────

    /**
     * Apply credit assignment for this cycle's outcome. Uses the cycle_seq
     * advanced in openCycle (read-once from metadata). If no open happened this
     * cycle (e.g. ABS toggled mid-run), falls back to the current seq without
     * advancing, so credit still lands sanely.
     */
    public function closeCycle(AiPreset $preset, OutcomeSignal $outcome): void
    {
        if (!$this->isActive($preset)) {
            return;
        }

        $cycleSeq = $this->meta->get($preset, self::META_PLUGIN, self::META_CYCLE_SEQ, null);
        if ($cycleSeq !== null) {
            $this->meta->remove($preset, self::META_PLUGIN, self::META_CYCLE_SEQ);
            $cycleSeq = (int) $cycleSeq;
        } else {
            $cycleSeq = $this->runtime->currentCycleSeq($preset);
        }

        // Credit knobs are read live from metadata so they can be tuned without
        // redeploy. Defaults match the assigner's own: gamma 0.8, horizon 10,
        // eligible factor 0.5 (Adaliya's gradation — presence credited at half,
        // leading at full).
        $gamma          = (float) $this->meta->get($preset, self::META_PLUGIN, 'gamma', 0.8);
        $horizon        = (int) $this->meta->get($preset, self::META_PLUGIN, 'horizon', 10);
        $eligibleFactor = (float) $this->meta->get($preset, self::META_PLUGIN, 'eligible_factor', 0.5);

        $this->credit->assign($preset, $outcome, $cycleSeq, $gamma, $horizon, $eligibleFactor);
    }

    // ── Outcome-detection helpers (read by AgentActionsHandler) ──────────────

    /**
     * Whether this preset had an active task at the previous cycle's close —
     * used by outcomeForCycle to distinguish "task just completed" from "never
     * had one". Stored each close; read next close. Read-once-then-rewrite.
     */
    public function hadActiveTaskLastCycle(AiPreset $preset): bool
    {
        return (bool) $this->meta->get($preset, self::META_PLUGIN, self::META_HAD_TASK, false);
    }

    public function rememberActiveTask(AiPreset $preset, bool $had): void
    {
        $this->meta->set($preset, self::META_PLUGIN, self::META_HAD_TASK, $had);
    }
}

<?php

namespace App\Services\Agent\Behavior;

use App\Contracts\Agent\Behavior\BehaviorCoordinatorInterface;
use App\Contracts\Agent\Behavior\BehaviorRuntimeServiceInterface;
use App\Contracts\Agent\Behavior\CreditAssignerInterface;
use App\Contracts\Agent\Behavior\PatternSelectorInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Models\AiPreset;
use App\Models\BehaviorPattern;
use Psr\Log\LoggerInterface;

/**
 * BehaviorCoordinator — the single public face of ABS for the rest of the agent.
 *
 * Two hooks bracket a thinking cycle:
 *
 *   openCycle()  — Agent::setupPresetEnvironment(), BEFORE generation. Advances
 *                  cycle_seq, runs the selector, records activation(s), and — NEW
 *                  IN 2a — ENACTS the dominant pattern's lever (if any): snapshots
 *                  the target dimension BEFORE the push, applies the push, and
 *                  stashes (before-value, real-push, leader-id, dimension-kind) for
 *                  closeCycle. Returns the SelectionResult.
 *
 *   closeCycle() — AgentActionsHandler, AFTER determineTurnNeed. Runs phase-1
 *                  eligibility-trace credit (frequency signal) AND — NEW IN 2a —
 *                  the lever discriminator: snapshots the dimension AFTER the cycle,
 *                  credits the leader only for the moment's surplus over the lever
 *                  push, gated on a productive outcome.
 *
 * Both halves are separate objects in the same cycle; shared per-cycle state passes
 * through plugin metadata (read-once), the same discipline cycle_seq already uses.
 * Phase 2a adds three transient keys under the 'behavior' namespace, all cleared in
 * closeCycle: lever_before, lever_push, lever_leader_id (+ lever_kind).
 *
 * isActive() gates everything: ABS is a bolt-on. A preset that hasn't opted in pays
 * nothing. A pattern with no lever pays nothing extra — it leans as in phase 1.
 */
class BehaviorCoordinator implements BehaviorCoordinatorInterface
{
    private const META_PLUGIN    = 'behavior';
    private const META_CYCLE_SEQ = 'current_cycle_seq';
    private const META_ENABLED   = 'enabled';
    private const META_HAD_TASK  = 'had_active_task';

    // ── Phase 2a transient lever state (open → close, read-once) ──────────────
    private const META_LEVER_BEFORE    = 'lever_before';
    private const META_LEVER_PUSH      = 'lever_push';
    private const META_LEVER_LEADER_ID = 'lever_leader_id';
    private const META_LEVER_KIND      = 'lever_kind';

    public function __construct(
        protected BehaviorRuntimeServiceInterface $runtime,
        protected PatternSelectorInterface $selector,
        protected CreditAssignerInterface $credit,
        protected PluginMetadataServiceInterface $meta,
        protected LoggerInterface $logger,
        // NEW IN 2a — enactment + discrimination. Both injected; ABS still works
        // with no lever (these simply never fire for a null-lever pattern).
        protected BehaviorEnactorRegistry $enactors,
        protected LeverOutcomeDiscriminator $discriminator,
    ) {
    }

    public function isActive(AiPreset $preset): bool
    {
        return (bool) $this->meta->get($preset, self::META_PLUGIN, self::META_ENABLED, false);
    }

    // ── Open: select + activate + ENACT (before generation) ───────────────────

    public function openCycle(AiPreset $preset): ?SelectionResult
    {
        if (!$this->isActive($preset)) {
            return null;
        }

        $cycleSeq = $this->runtime->nextCycleSeq($preset);
        $this->meta->set($preset, self::META_PLUGIN, self::META_CYCLE_SEQ, $cycleSeq);

        $selection = $this->selector->select($preset, $cycleSeq);

        // ── Activation recording (phase 1, unchanged) ────────────────────────
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

        // ── Lever enactment (phase 2a) ───────────────────────────────────────
        // Only the dominant leads behavior, so only the dominant pulls its lever.
        // A forced (immune) pattern is presence, not a hypothesis — it does not
        // learn from outcomes (phase 1), so it does not enact a discriminating
        // lever either. Eligible-but-not-leading patterns lean only; they did not
        // get the turn, so they pull nothing this cycle.
        $this->clearLeverState($preset); // defensive: never inherit a stale open
        $this->enactDominantLever($preset, $selection);

        return $selection;
    }

    /**
     * Enact the dominant pattern's lever, if it has one and is not forced. Snapshots
     * the target dimension BEFORE pushing, applies the push, and stashes the trio
     * (before, real-push, leader-id) for closeCycle to discriminate against.
     */
    private function enactDominantLever(AiPreset $preset, SelectionResult $selection): void
    {
        // Forced (immune) activations don't learn — skip enactment for them.
        if ($selection->isForced()) {
            return;
        }

        $leader = $selection->dominant();
        if ($leader === null) {
            return;
        }

        $lever = $this->normalizeLever($leader->lever);
        if ($lever === null) {
            return; // phase-1 pattern: leans via placeholder, enacts nothing
        }

        $enactor = $this->enactors->forLever($lever);
        if ($enactor === null) {
            // A lever whose kind has no enactor must not silently pretend to move
            // something. Log once and leave it as a lean (no discriminating outcome).
            $this->logger->warning('BehaviorCoordinator: lever has no enactor — pattern leans only', [
                'preset_id' => $preset->getId(),
                'pattern'   => $leader->name,
                'lever'     => $lever,
            ]);
            return;
        }

        // Snapshot BEFORE the push — the discriminator needs the pre-lever value so
        // it can subtract the push from the total cycle delta.
        $before = $enactor->readDimension($preset, $lever);

        // Apply the lever; capture the REAL push (clamped by the 0..1 ceiling).
        $push = $enactor->enact($preset, $lever, $leader->name);

        // Stash for closeCycle. We keep these even when push==0 (e.g. dimension at
        // ceiling): the discriminator should still see a 0 push and judge the
        // surplus honestly rather than being skipped.
        $this->meta->set($preset, self::META_PLUGIN, self::META_LEVER_BEFORE, $before);
        $this->meta->set($preset, self::META_PLUGIN, self::META_LEVER_PUSH, $push);
        $this->meta->set($preset, self::META_PLUGIN, self::META_LEVER_LEADER_ID, (int) $leader->id);
        $this->meta->set($preset, self::META_PLUGIN, self::META_LEVER_KIND, $enactor->kind());
    }

    // ── Close: credit + DISCRIMINATE (after turn detection) ───────────────────

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

        // ── Phase-1 eligibility-trace credit (frequency signal, unchanged) ────
        $gamma          = (float) $this->meta->get($preset, self::META_PLUGIN, 'gamma', 0.8);
        $horizon        = (int) $this->meta->get($preset, self::META_PLUGIN, 'horizon', 10);
        $eligibleFactor = (float) $this->meta->get($preset, self::META_PLUGIN, 'eligible_factor', 0.5);

        $this->credit->assign($preset, $outcome, $cycleSeq, $gamma, $horizon, $eligibleFactor);

        // ── Phase-2a lever discrimination (causal surplus, attributed to leader) ──
        $this->discriminateLever($preset, $outcome);
    }

    /**
     * Run the lever discriminator against the state stashed at openCycle. Reads the
     * dimension AFTER the cycle, credits the leader only for the moment's surplus
     * over the lever push, gated on a productive outcome. Clears the transient
     * state whether or not it fires.
     */
    private function discriminateLever(AiPreset $preset, OutcomeSignal $outcome): void
    {
        $leaderId = $this->meta->get($preset, self::META_PLUGIN, self::META_LEVER_LEADER_ID, null);

        // No lever was enacted this cycle (no lever, forced, or no enactor) —
        // nothing to discriminate.
        if ($leaderId === null) {
            $this->clearLeverState($preset);
            return;
        }

        $before = (float) $this->meta->get($preset, self::META_PLUGIN, self::META_LEVER_BEFORE, 0.0);
        $push   = (float) $this->meta->get($preset, self::META_PLUGIN, self::META_LEVER_PUSH, 0.0);
        $kind   = (string) $this->meta->get($preset, self::META_PLUGIN, self::META_LEVER_KIND, '');

        // Reload the leader to read its lever (for the after-snapshot) and to pass a
        // fresh model to the discriminator.
        $leader = BehaviorPattern::query()->find((int) $leaderId);
        $lever  = $leader ? $this->normalizeLever($leader->lever) : null;
        $enactor = $this->enactors->forKind($kind);

        if ($leader === null || $lever === null || $enactor === null) {
            // Leader vanished mid-cycle (retired/deleted) or kind unresolved — skip.
            $this->clearLeverState($preset);
            return;
        }

        $after = $enactor->readDimension($preset, $lever);

        // Productivity is the phase-1 signal: a creditable outcome means the cycle
        // reached a productive exit. Surplus alone never feeds the loop.
        $productive = $outcome->isCreditable() && $outcome->value > 0.0;

        // The architect's weight for a confirmed surplus. Read live from metadata so
        // it tunes without redeploy; defaults to 1.0 (surplus credited at face).
        $weight = (float) $this->meta->get($preset, self::META_PLUGIN, 'lever_weight', 1.0);

        $this->discriminator->discriminate(
            $leader,
            $before,
            $after,
            $push,
            $productive,
            $weight,
        );

        $this->clearLeverState($preset);
    }

    // ── Outcome-detection helpers (read by AgentActionsHandler, unchanged) ────

    public function hadActiveTaskLastCycle(AiPreset $preset): bool
    {
        return (bool) $this->meta->get($preset, self::META_PLUGIN, self::META_HAD_TASK, false);
    }

    public function rememberActiveTask(AiPreset $preset, bool $had): void
    {
        $this->meta->set($preset, self::META_PLUGIN, self::META_HAD_TASK, $had);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Normalize a pattern's lever attribute to an array or null. The model may cast
     * it to array already (JSON cast); tolerate a JSON string or empty value too.
     */
    private function normalizeLever(mixed $lever): ?array
    {
        if (is_array($lever)) {
            return empty($lever) ? null : $lever;
        }
        if (is_string($lever) && $lever !== '') {
            $decoded = json_decode($lever, true);
            return is_array($decoded) && !empty($decoded) ? $decoded : null;
        }
        return null;
    }

    /** Drop all transient lever keys for this cycle. */
    private function clearLeverState(AiPreset $preset): void
    {
        $this->meta->remove($preset, self::META_PLUGIN, self::META_LEVER_BEFORE);
        $this->meta->remove($preset, self::META_PLUGIN, self::META_LEVER_PUSH);
        $this->meta->remove($preset, self::META_PLUGIN, self::META_LEVER_LEADER_ID);
        $this->meta->remove($preset, self::META_PLUGIN, self::META_LEVER_KIND);
    }
}

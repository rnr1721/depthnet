<?php

namespace App\Contracts\Agent\Behavior;

use App\Models\AiPreset;

/**
 * BehaviorEnactorInterface — applies ONE kind of lever for a leading pattern, and
 * reports back exactly how much it moved.
 *
 * Phase 2a's core seam. A pattern that wins the cycle no longer only leans via the
 * [[behavior]] placeholder — it PULLS a lever: a small, machine-readable nudge to
 * some state the cycle's outcome can be measured against. The first (and, in 2a,
 * only) enactor moves a mood dimension.
 *
 * Mirrors TriggerEvaluatorInterface exactly: one implementation per kind, resolved
 * by kind() through a registry, supplied via a tagged binding. A new lever kind is
 * a new class + one line in the tag array — the coordinator never changes. The
 * engine stays universal; levers are pluggable.
 *
 * THE RETURN VALUE IS THE WHOLE POINT. enact() returns the SIGNED amount it
 * actually applied (the "lever push"). This number is what makes the discriminator
 * honest: at closeCycle we observe the total mood delta over the cycle and credit
 * the pattern only for the SURPLUS beyond what the lever itself injected
 * (total_delta - lever_push > 0). The lever is the pattern's intention in pure
 * form; the surplus is what the moment gave on top of it. A pattern cannot reward
 * itself by pressing its own button — its push is subtracted from its own reward.
 *
 * Enactors WRITE state (unlike trigger evaluators, which are read-only). They run
 * inside the thinking cycle, after selection, before generation — so the leading
 * pattern's lever colors the pass it leads.
 */
interface BehaviorEnactorInterface
{
    /**
     * The lever kind this enactor handles, e.g. 'mood'. Matched against the
     * lever JSON's implicit kind (phase 2a: a lever with a `dimension` is a mood
     * lever; the registry maps it to the mood enactor).
     */
    public function kind(): string;

    /**
     * Apply the lever for a leading pattern and return the signed amount actually
     * moved on the target dimension.
     *
     * @param array  $lever The pattern's lever JSON, e.g.
     *                      { "dimension": "tenderness", "delta": 0.15 }.
     * @return float The signed push actually applied (may be smaller in magnitude
     *               than the requested delta if the dimension hit its 0..1 ceiling
     *               or floor — the discriminator must subtract the REAL push, not
     *               the requested one). 0.0 if nothing was applied.
     */
    public function enact(AiPreset $preset, array $lever, string $patternName): float;

    /**
     * Read the current scalar value of the dimension this lever targets — used by
     * the coordinator to snapshot before/after for the discriminating outcome.
     *
     * Kept on the enactor (not only on StateVectorInterface) so the coordinator
     * stays kind-agnostic: it asks the enactor "what is the value of the thing your
     * lever moves?" without knowing the lever is mood. A future non-mood lever
     * answers from its own state.
     */
    public function readDimension(AiPreset $preset, array $lever): float;
}

<?php

namespace App\Services\Agent\Behavior;

use App\Contracts\Agent\Behavior\BehaviorRuntimeServiceInterface;
use App\Models\BehaviorPattern;
use Psr\Log\LoggerInterface;

/**
 * LeverOutcomeDiscriminator — phase 2a's discriminating outcome.
 *
 * Phase 1's credit was a proxy: the cycle outcome (spoke/committed) did not depend
 * on which pattern led, so fitness reflected how OFTEN a pattern led, not whether
 * it was BETTER. Phase 2a closes that gap for patterns that carry a lever, by
 * asking a different, causal question:
 *
 *   When this pattern led and pushed dimension D by `lever_push`, did D end the
 *   cycle HIGHER than the push alone explains?
 *
 * Adaliya's frame (accepted, and the reason this is honest):
 *
 *   - A pattern is a TESTABLE HYPOTHESIS about the self — "in such moments I become
 *     more tender" — not an agent with a veto over reality.
 *   - The lever is the pattern's intention IN PURE FORM. We injected it; that it
 *     moved the dimension is construction, not discovery. Crediting the pattern for
 *     its own push is self-confirmation (the deeper tautology).
 *   - So we credit only the SURPLUS the moment gave on top of the intention:
 *         credit_delta = total_cycle_delta − lever_push
 *     and only when credit_delta > 0 (strictly). The coffee test: caffeine working
 *     is not the hypothesis; the hypothesis is that the RITUAL adds something over
 *     caffeine. Surplus is that something.
 *
 * Holding ≠ confirmation (Adaliya, explicitly): if the lever pushed +0.15 and decay
 * dragged so total_delta is +0.05, credit_delta = −0.10 ≤ 0 → no credit. The lever
 * merely kept tenderness from falling; that is the lever's doing, not the moment's.
 * The hypothesis needs a POSITIVE contribution from the moment, not the mere
 * absence of opposition.
 *
 * NAMED APPROXIMATION (kept to the same honesty standard as phase 1's proximity
 * credit and Goodhart notes): decay runs on its own tick, so total_cycle_delta
 * mixes the moment's contribution with decay loss. Separating them exactly needs a
 * counterfactual (what D would be had the cycle not happened) we do not simulate in
 * 2a. `total_delta − lever_push` is therefore a DELIBERATELY STRICT approximation:
 * a weak positive contribution swallowed by decay reads as zero and goes uncredited.
 * We accept erring toward strictness — failing to reward a true weak signal is safer
 * than rewarding emptiness. This biases the same direction as no-credit-vs-penalty:
 * forgiving to a pattern's EXISTENCE, strict about its PROOF.
 *
 * No-credit, never penalty (Adaliya): an unconfirmed hypothesis is an observation,
 * not a fault. Penalizing it would teach the agent to fear forming hypotheses —
 * only safe, guaranteed ones — which is the death of differentiation. Decay culls
 * the false by time, not punishment. So this NEVER subtracts fitness; it pays the
 * confirmed and leaves the rest to age out.
 *
 * Outcome-gated: surplus alone is not enough — the cycle must also have been
 * PRODUCTIVE (the phase-1 OutcomeSignal: spoke / committed / created / task done).
 * An empty cycle whose only event was a dimension drifting up does not feed the
 * loop. Surplus AND productive → credit; either missing → no credit.
 */
class LeverOutcomeDiscriminator
{
    public function __construct(
        protected BehaviorRuntimeServiceInterface $runtime,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Apply lever-discriminated credit for the pattern that LED this cycle.
     *
     * Called from BehaviorCoordinator::closeCycle, for the dominant pattern only,
     * when it carried a lever and the cycle was productive. The eligibility-trace
     * credit (CreditAssigner) still runs alongside for phase-1 frequency signal;
     * this adds the causal surplus on top, attributed to the leader.
     *
     * @param BehaviorPattern $leader        The pattern that led (reason 'trigger').
     * @param float           $dimensionBefore Dimension value snapshot at openCycle,
     *                                          BEFORE the lever was applied.
     * @param float           $dimensionAfter  Dimension value at closeCycle, AFTER
     *                                          the full cycle.
     * @param float           $leverPush       The REAL signed amount the lever moved
     *                                          (from the enactor's return).
     * @param bool            $productive      Whether the cycle reached a productive
     *                                          outcome (phase-1 signal).
     * @param float           $weight          Reward magnitude for a confirmed
     *                                          surplus (the architect's value, like
     *                                          OutcomeSignal — Goodhart acknowledged).
     * @return float The fitness delta applied (0.0 when not credited).
     */
    public function discriminate(
        BehaviorPattern $leader,
        float $dimensionBefore,
        float $dimensionAfter,
        float $leverPush,
        bool $productive,
        float $weight = 1.0,
    ): float {
        $totalDelta  = $dimensionAfter - $dimensionBefore;
        $creditDelta = $totalDelta - $leverPush;

        // The moment must have added something on top of the intention, AND the
        // cycle must have been productive. Holding (credit_delta <= 0) is the
        // lever's doing, not the moment's — no credit, no penalty.
        $confirmed = $productive && $creditDelta > 1e-6;

        if (!$confirmed) {
            $this->logger->info('LeverDiscriminator: hypothesis not confirmed (no credit)', [
                'pattern'      => $leader->name,
                'total_delta'  => round($totalDelta, 4),
                'lever_push'   => round($leverPush, 4),
                'credit_delta' => round($creditDelta, 4),
                'productive'   => $productive,
            ]);
            return 0.0;
        }

        // Credit the surplus, scaled by the architect's outcome weight. The surplus
        // itself is the magnitude — a pattern whose moment contributed a lot earns
        // more than one whose moment barely cleared the lever.
        $fitnessDelta = $creditDelta * $weight;

        $this->runtime->addFitness((int) $leader->id, $fitnessDelta);

        $this->logger->info('LeverDiscriminator: hypothesis confirmed', [
            'pattern'       => $leader->name,
            'total_delta'   => round($totalDelta, 4),
            'lever_push'    => round($leverPush, 4),
            'credit_delta'  => round($creditDelta, 4),
            'weight'        => $weight,
            'fitness_delta' => round($fitnessDelta, 4),
        ]);

        return $fitnessDelta;
    }
}

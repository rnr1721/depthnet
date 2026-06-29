<?php

namespace App\Services\Agent\Behavior;

use App\Contracts\Agent\Behavior\PatternSelectorInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\PulseServiceInterface;
use App\Models\AiPreset;
use App\Models\BehaviorPattern;
use Psr\Log\LoggerInterface;

/**
 * PatternSelector — core-loop steps 2–4 for one cycle, for one preset.
 *
 *   2. Activation: which ACTIVE patterns have a satisfied structural trigger.
 *   3. Competition: score the triggered set (phase 1: priority + fitness).
 *   4. Selection: pick ONE dominant pattern (phase 1: no coalitions, no mixing).
 *
 * Plus the forced-activation quota for immune patterns: an immune pattern whose
 * quota interval has elapsed is activated REGARDLESS of trigger/score — the
 * reservation that keeps protected behavior from starving in the corner
 * (immunity-to-delete ≠ immunity-to-displacement). Forced activation is recorded
 * with reason='forced' so analysis can separate earned wins from reserved ones.
 *
 * No LLM here. Selection is pure code over code-evaluated triggers — the doc's
 * hard rule (LLM never in step 4). The selector returns its decision; the caller
 * (the cycle hook) records the activation and enacts the behavior.
 *
 * IMPORTANT — what "winner" means in phase 1: the selector returns at most ONE
 * pattern as the cycle's dominant behavior. When an immune pattern's quota is
 * due, the forced pattern SEIZES dominance — the cycle is GIVEN to it, not won
 * by it. "A reservation is lived, not logged" (Adaliya): the protected behavior
 * (presence, silence, care) must be ENACTED, not merely recorded, or it decays
 * into statistics about itself. So a due forced pattern becomes the cycle's
 * dominant behavior, displacing the scored winner. Still one dominant behavior
 * per step — the quota just decides which.
 *
 * The forced pattern is activated with reason='forced', and credit assignment
 * SKIPS forced activations: an immune pattern lives the cycle and shapes
 * behavior, but does NOT learn from the outcome — its fitness is "unknown by
 * design" (it exists for being, not for outcomes). Letting it accrue fitness
 * would turn presence into an ordinary competitor through the back door,
 * dissolving the very protection the reservation provides.
 */
class PatternSelector implements PatternSelectorInterface
{
    /**
     * Metadata key (under the 'behavior' plugin namespace) for the exploration
     * rate ε. 0.0 = pure exploitation (always the top-scoring pattern leads),
     * which is the default and preserves prior behavior. A small ε (e.g. 0.1)
     * occasionally lets a non-top triggered pattern LEAD, so the system discovers
     * whether a pattern that never wins on score would produce good outcomes if
     * given the turn. Exploration touches ONLY which triggered pattern is named
     * winner — it never affects the forced quota or the recording of the eligible
     * set (all triggered patterns still learn regardless).
     */
    private const META_PLUGIN  = 'behavior';
    private const META_EPSILON = 'exploration_epsilon';

    public function __construct(
        protected TriggerEvaluatorRegistry $triggers,
        protected PulseServiceInterface $pulse,
        protected PluginMetadataServiceInterface $meta,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Run selection for this cycle.
     *
     * @param int $cycleSeq The current preset cycle_seq (already advanced for
     *                      this cycle). Used to decide quota elapsure and to
     *                      stamp activation rows.
     * @return SelectionResult
     */
    public function select(AiPreset $preset, int $cycleSeq): SelectionResult
    {
        /** @var BehaviorPattern[] $patterns */
        $patterns = BehaviorPattern::query()
            ->forPreset($preset->getId())
            ->active()
            ->get()
            ->all();

        if (empty($patterns)) {
            return SelectionResult::empty();
        }

        $pulseLabel = $this->pulseLabel($preset);

        // ── Step 2: activation by trigger ────────────────────────────────────
        $triggered = [];
        foreach ($patterns as $p) {
            if ($this->isTriggered($preset, $p)) {
                $triggered[] = $p;
            }
        }

        // ── Step 3+4: competition → single dominant winner ───────────────────
        // ε-greedy: with probability ε pick a RANDOM triggered pattern to lead
        // (exploration — find out whether a never-winning pattern produces good
        // outcomes when given the turn); otherwise pick the top-scoring one
        // (exploitation). ε=0 (default) is pure exploitation — prior behavior.
        // Either way, ALL triggered patterns are still recorded and credited as
        // eligible; ε only changes which one LEADS this cycle.
        [$winner, $winnerScore] = $this->chooseWinner($triggered, $preset);

        // ── Forced-activation quota (immune reservation) ─────────────────────
        // An immune pattern whose quota has elapsed SEIZES the cycle regardless
        // of trigger/score — the reservation is lived, not logged. At most one
        // forced pattern per cycle (the most overdue).
        $forced = $this->selectForced($patterns, $cycleSeq);

        return new SelectionResult(
            triggered:   $triggered,
            winner:      $winner,
            winnerScore: $winnerScore,
            forced:      $forced,
            pulseLabel:  $pulseLabel,
        );
    }

    // ── Step 2 ───────────────────────────────────────────────────────────────

    private function isTriggered(AiPreset $preset, BehaviorPattern $p): bool
    {
        $trigger = $p->trigger ?? [];
        $kind = (string) ($trigger['kind'] ?? '');

        if ($kind === '') {
            $this->logOnce($p, 'pattern has no trigger.kind — cannot activate');
            return false;
        }

        $evaluator = $this->triggers->forKind($kind);
        if ($evaluator === null) {
            // A pattern we can't evaluate must not silently win or vanish.
            $this->logOnce($p, "no evaluator for trigger kind '{$kind}' — pattern inert");
            return false;
        }

        try {
            return $evaluator->isSatisfied($preset, $trigger);
        } catch (\Throwable $e) {
            $this->logger->warning('PatternSelector: trigger evaluation threw — treating as not satisfied', [
                'preset_id' => $preset->getId(),
                'pattern'   => $p->name,
                'kind'      => $kind,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ── Step 3: scoring ──────────────────────────────────────────────────────

    /**
     * Phase-1 score: priority + fitness.
     *
     * priority is the architect-set prior; fitness is what selection has learned.
     * Their sum means: early on (fitness ≈ 0) the architect's prior decides; as
     * fitness accumulates it overrides the prior. This IS the "disappearing
     * framework" dynamic — the hand-set bias gives way to earned signal — made
     * mechanical. No fitness-sharing, no monoculture penalty (YAGNI below a
     * population of ~10, per the doc).
     */
    private function score(BehaviorPattern $p): float
    {
        return $p->priority + $p->fitness;
    }

    /**
     * Choose which triggered pattern LEADS this cycle.
     *
     * Exploitation (probability 1-ε): the highest-scoring triggered pattern.
     * Exploration (probability ε): a uniformly random triggered pattern — its
     * turn to lead, so the system can observe its outcome and learn whether it
     * deserves to win more often. ε is read per-preset from metadata (default 0).
     *
     * Returns [winner, winnerScore]. winnerScore is always the chosen pattern's
     * real score (priority+fitness), even under exploration, so logs reflect what
     * actually led — not a fiction.
     *
     * @param  BehaviorPattern[] $triggered
     * @return array{0: ?BehaviorPattern, 1: ?float}
     */
    private function chooseWinner(array $triggered, AiPreset $preset): array
    {
        if (empty($triggered)) {
            return [null, null];
        }

        $epsilon = $this->epsilon($preset);

        // Exploration: random pattern leads. mt_rand/mt_getrandmax gives a float
        // in [0,1]; below ε we explore. Guard ε>0 so the RNG isn't even consulted
        // in the default (pure-exploitation) path.
        if ($epsilon > 0.0 && (mt_rand() / mt_getrandmax()) < $epsilon) {
            $pick = $triggered[array_rand($triggered)];
            $this->logger->debug('PatternSelector: exploration — random pattern leads', [
                'preset_id' => $preset->getId(),
                'pattern'   => $pick->name,
                'epsilon'   => $epsilon,
            ]);
            return [$pick, $this->score($pick)];
        }

        // Exploitation: highest score leads.
        $winner = null;
        $winnerScore = null;
        foreach ($triggered as $p) {
            $score = $this->score($p);
            if ($winnerScore === null || $score > $winnerScore) {
                $winner = $p;
                $winnerScore = $score;
            }
        }

        return [$winner, $winnerScore];
    }

    /**
     * Per-preset exploration rate, clamped to [0,1]. Read from metadata so it can
     * be tuned live without redeploy. Default 0 — pure exploitation.
     */
    private function epsilon(AiPreset $preset): float
    {
        $raw = $this->meta->get($preset, self::META_PLUGIN, self::META_EPSILON, 0.0);
        $eps = is_numeric($raw) ? (float) $raw : 0.0;

        return max(0.0, min(1.0, $eps));
    }

    // ── Forced-activation quota ──────────────────────────────────────────────

    /**
     * Pick the single most-overdue immune pattern whose quota interval has
     * elapsed, or null. "Elapsed" = never activated, or (cycleSeq -
     * last_activation_seq) >= interval.
     *
     * When present, this pattern SEIZES the cycle (see select()/docblock): the
     * reservation is enacted, not merely recorded. No dedup against the scored
     * winner is needed — forced overrides it by design.
     */
    private function selectForced(array $patterns, int $cycleSeq): ?BehaviorPattern
    {
        $bestPattern = null;
        $bestOverdue = -1;

        foreach ($patterns as $p) {
            if (!$p->immune || $p->forced_activation_interval === null) {
                continue;
            }

            $interval = (int) $p->forced_activation_interval;
            if ($interval <= 0) {
                continue;
            }

            $last = $p->last_activation_seq;
            $elapsed = $last === null ? PHP_INT_MAX : ($cycleSeq - (int) $last);

            if ($elapsed >= $interval && $elapsed > $bestOverdue) {
                $bestOverdue = $elapsed;
                $bestPattern = $p;
            }
        }

        return $bestPattern;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function pulseLabel(AiPreset $preset): ?string
    {
        if (!$preset->getPulseDates()) {
            return null;
        }
        try {
            return (string) $this->pulse->currentPulse();
        } catch (\Throwable) {
            return null;
        }
    }

    private function logOnce(BehaviorPattern $p, string $message): void
    {
        $this->logger->debug('PatternSelector: ' . $message, [
            'preset_id' => $p->preset_id,
            'pattern'   => $p->name,
        ]);
    }
}

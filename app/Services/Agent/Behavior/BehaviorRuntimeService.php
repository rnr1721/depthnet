<?php

namespace App\Services\Agent\Behavior;

use App\Contracts\Agent\Behavior\BehaviorRuntimeServiceInterface;
use App\Models\AiPreset;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * BehaviorRuntimeService — ABS engine hot-path state.
 *
 * Writes the per-cycle columns of behavior_patterns / pattern_activations /
 * behavior_runtime through the query builder directly (not Eloquent, not a CRUD
 * service), exactly as ContractRuntimeService does against agent_contracts. This
 * keeps per-cycle fitness/seq writes off the JSON-metadata path and lets us use
 * atomic increments instead of read-modify-write.
 *
 * Responsibilities:
 *   - own and advance cycle_seq (the selection time-axis);
 *   - append activation rows (the eligibility trace);
 *   - apply fitness deltas atomically;
 *   - serve the credit step its uncredited-activation window.
 *
 * It does NOT decide WHICH patterns activate (that's the selector, in the
 * thinking cycle) or HOW MUCH credit to assign (that's CreditAssigner) — it is
 * the persistence muscle those use.
 */
class BehaviorRuntimeService implements BehaviorRuntimeServiceInterface
{
    private const RUNTIME_TABLE     = 'behavior_runtime';
    private const PATTERNS_TABLE    = 'behavior_patterns';
    private const ACTIVATIONS_TABLE = 'pattern_activations';

    public function __construct(
        protected DatabaseManager $db,
        protected LoggerInterface $logger,
    ) {
    }

    // ── Cycle axis ─────────────────────────────────────────────────────────────

    /**
     * Atomically advance and return the preset's completed-cycle counter.
     *
     * Called once per cycle, at outcome registration in AgentActionsHandler.
     * Lazily creates the runtime row on first use so presets that never run ABS
     * never get one. Uses a transaction + lockForUpdate to make the read-modify
     * safe under the (rare) concurrent-cycle case; cheap because it touches one
     * row keyed by primary key.
     */
    public function nextCycleSeq(AiPreset $preset): int
    {
        return $this->db->transaction(function () use ($preset) {
            $presetId = $preset->getId();

            $current = $this->db->table(self::RUNTIME_TABLE)
                ->where('preset_id', $presetId)
                ->lockForUpdate()
                ->value('cycle_seq');

            if ($current === null) {
                $this->db->table(self::RUNTIME_TABLE)->insert([
                    'preset_id'  => $presetId,
                    'cycle_seq'  => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                return 1;
            }

            $next = (int) $current + 1;

            $this->db->table(self::RUNTIME_TABLE)
                ->where('preset_id', $presetId)
                ->update(['cycle_seq' => $next, 'updated_at' => now()]);

            return $next;
        });
    }

    /** Read the current cycle_seq without advancing (0 if ABS never ran here). */
    public function currentCycleSeq(AiPreset $preset): int
    {
        return (int) ($this->db->table(self::RUNTIME_TABLE)
            ->where('preset_id', $preset->getId())
            ->value('cycle_seq') ?? 0);
    }

    // ── Activation log ─────────────────────────────────────────────────────────

    /**
     * Record that a pattern activated this cycle. Appends an eligibility-trace
     * row and bumps the pattern's own activation bookkeeping atomically.
     *
     * @param string $reason 'trigger' (won competition) | 'forced' (quota)
     */
    public function recordActivation(
        AiPreset $preset,
        int $patternId,
        int $cycleSeq,
        string $reason = 'trigger',
        ?string $pulseLabel = null,
    ): void {
        $now = now();

        $this->db->table(self::ACTIVATIONS_TABLE)->insert([
            'preset_id'   => $preset->getId(),
            'pattern_id'  => $patternId,
            'cycle_seq'   => $cycleSeq,
            'reason'      => $reason,
            'credited'    => false,
            'pulse_label' => $pulseLabel,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->db->table(self::PATTERNS_TABLE)
            ->where('id', $patternId)
            ->update([
                'activation_count'    => $this->db->raw('activation_count + 1'),
                'last_activation_seq' => $cycleSeq,
                'updated_at'          => $now,
            ]);
    }

    // ── Credit window ──────────────────────────────────────────────────────────

    /**
     * Uncredited, CREDITABLE activations within the credit horizon of the current
     * cycle, newest first. The credit step walks these and pays each by proximity.
     *
     * Creditable reasons: 'trigger' (led behavior) and 'eligible' (triggered but
     * did not lead — path A: all who were ready learn). Both are paid.
     *
     * Forced (reason='forced') activations are EXCLUDED: an immune pattern lives
     * its quota cycle and shapes behavior, but does not learn from the outcome —
     * its fitness is unknown by design. Crediting it would turn presence into an
     * ordinary competitor through the back door. The exclusion is enforced here,
     * at the source (everything except 'forced' is paid), so no caller can
     * accidentally pay a forced row, and any future non-forced reason is creditable
     * by default.
     *
     * @return array<int,object> rows: {id, pattern_id, cycle_seq, reason}
     */
    public function uncreditedWithin(AiPreset $preset, int $currentCycleSeq, int $horizon): array
    {
        $floor = max(0, $currentCycleSeq - $horizon);

        return $this->db->table(self::ACTIVATIONS_TABLE)
            ->where('preset_id', $preset->getId())
            ->where('credited', false)
            ->where('reason', '!=', 'forced')
            ->where('cycle_seq', '>=', $floor)
            ->orderByDesc('cycle_seq')
            ->get(['id', 'pattern_id', 'cycle_seq', 'reason'])
            ->all();
    }

    /** Mark an activation row paid, recording the credit applied (audit). */
    public function markCredited(int $activationId, float $creditApplied): void
    {
        $this->db->table(self::ACTIVATIONS_TABLE)
            ->where('id', $activationId)
            ->update([
                'credited'       => true,
                'credit_applied' => $creditApplied,
                'updated_at'     => now(),
            ]);
    }

    // ── Fitness ────────────────────────────────────────────────────────────────

    /**
     * Apply a fitness delta to a pattern atomically. Positive or negative.
     * No clamp here — clamping/normalisation is a selection-policy concern, kept
     * out of the persistence muscle so the policy can change without a migration.
     */
    public function addFitness(int $patternId, float $delta): void
    {
        $this->db->table(self::PATTERNS_TABLE)
            ->where('id', $patternId)
            ->update([
                'fitness'    => $this->db->raw('fitness + (' . $this->lit($delta) . ')'),
                'updated_at' => now(),
            ]);
    }

    /**
     * Decay fitness for patterns that did NOT activate at/after the given seq
     * floor — the inactivity decay run by the separate tick. Multiplicative:
     * fitness *= factor. Touches only non-immune-OR-still patterns? No — decay
     * applies to ALL patterns uniformly (immune protects from deletion, not from
     * fitness gravity); the quota, not a decay exemption, is what keeps an immune
     * pattern alive. Done as one indexed UPDATE.
     *
     * @param float $factor 0..1 (e.g. 0.98 per idle tick)
     */
    public function decayInactive(AiPreset $preset, int $seqFloor, float $factor): int
    {
        return $this->db->table(self::PATTERNS_TABLE)
            ->where('preset_id', $preset->getId())
            ->where('status', 'active')
            ->where(function ($q) use ($seqFloor) {
                $q->whereNull('last_activation_seq')
                  ->orWhere('last_activation_seq', '<', $seqFloor);
            })
            ->update([
                'fitness'    => $this->db->raw('fitness * (' . $this->lit($factor) . ')'),
                'updated_at' => now(),
            ]);
    }

    /**
     * Float literal for a raw expression. Cast through a safe format so a locale
     * or scientific-notation surprise never lands in SQL. Values here are
     * engine-internal (gamma powers, decay factors), never user input, but we
     * format defensively anyway.
     */
    private function lit(float $v): string
    {
        return number_format($v, 8, '.', '');
    }
}

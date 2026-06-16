<?php

namespace App\Contracts\Agent\Contract;

use App\Models\AiPreset;
use Illuminate\Support\Carbon;

/**
 * ContractRuntimeServiceInterface — the engine's per-tick state, kept on the
 * contract rows (not in preset metadata, to avoid blob contention with mood).
 *
 * Responsibilities, all table-backed:
 *   - remember whether each contract was triggered (for edge detection),
 *   - record each evaluation (triggered, triggered_at, last_evaluated_at),
 *   - derive the set of currently raised flags (active + triggered + flag action),
 *   - answer "is this flag raised?" (for suspend_when),
 *   - report the last tick time (max last_evaluated_at) for dt.
 *
 * This is deliberately separate from ContractServiceInterface (CRUD + lifecycle):
 * that service deals in immutable DTOs and human-driven changes; this one is the
 * engine's hot path and writes columns directly.
 */
interface ContractRuntimeServiceInterface
{
    /**
     * Was this contract triggered as of its last recorded evaluation?
     * Used for rising/falling edge detection.
     */
    public function previousTriggered(AiPreset $preset, string $name): bool;

    /**
     * Persist the result of evaluating a contract this tick.
     *
     * @param bool   $triggered  whether the condition is met now.
     * @param bool   $risingEdge whether this is a not-met → met transition
     *                           (sets triggered_at; falling clears it).
     */
    public function recordEvaluation(
        AiPreset $preset,
        string $name,
        bool $triggered,
        bool $risingEdge,
        Carbon $now
    ): void;

    /**
     * Currently raised flags, derived from active+triggered contracts whose
     * action is set_flag or create_goal.
     *
     * @return array<int, array{flag:string, by:string, kind:string, since:?string}>
     */
    public function raisedFlags(AiPreset $preset): array;

    /**
     * Whether a given flag is currently raised (for suspend_when checks).
     */
    public function isFlagRaised(AiPreset $preset, string $flag): bool;

    /**
     * The most recent evaluation time across the preset's contracts, used as the
     * "last tick" anchor for dt. Null before the first tick.
     */
    public function lastTickAt(AiPreset $preset): ?Carbon;
}

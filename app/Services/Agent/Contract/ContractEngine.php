<?php

namespace App\Services\Agent\Contract;

use App\Contracts\Agent\Contract\ContractMemoWriterInterface;
use App\Contracts\Agent\Contract\ContractRuntimeServiceInterface;
use App\Contracts\Agent\Contract\ContractServiceInterface;
use App\Contracts\Agent\Contract\StateVectorInterface;
use App\Models\AiPreset;
use Psr\Log\LoggerInterface;

/**
 * ContractEngine — the metabolism's heart.
 *
 * One tick over one preset: load active contracts, evaluate each by form, detect
 * rising/falling edges, run actions, and persist runtime state. No language model
 * is involved. The engine works only with ContractDefinition DTOs (never Eloquent)
 * and reaches storage solely through the runtime service and trace readers.
 *
 * Forms:
 *   THR_T — fires when seconds since the last matching trace ≥ threshold.
 *   THR_C — fires when matching traces in the window ≥ threshold.
 *   ACC   — every tick: value += weight·dt (capped); fires on reaching cap.
 *   DEC   — every tick: value -= rate (floored); fires on reaching floor.
 *
 * Edge semantics (uniform across forms):
 *   rising edge (not met → met):  run the action.
 *   held (met → met):             nothing (one-shots don't re-fire; flags persist
 *                                 because they're DERIVED from `triggered`).
 *   falling edge (met → not met): `triggered` goes false → derived flag drops.
 *
 * So set_flag / create_goal need no write here — recording `triggered` is what
 * raises/clears the derived flag. Only nudge_state and inject_memo do work on the
 * rising edge.
 */
class ContractEngine
{
    public function __construct(
        protected ContractServiceInterface        $contracts,
        protected ContractRuntimeServiceInterface $runtime,
        protected StateVectorInterface            $vector,
        protected TraceReaderRegistry             $readers,
        protected LoggerInterface                 $logger,
        protected ?ContractMemoWriterInterface    $memo = null,
    ) {
    }

    /**
     * Tick one preset.
     *
     * @param string|null $globalSuspendFlag  flag that, while raised, suspends
     *                                        every contract this tick (plugin's
     *                                        default_suspend_flag).
     * @param int $minIntervalSeconds         coalesce: if less than this many
     *                                        seconds since the last tick, skip.
     *                                        0 = never skip (driver controls cadence).
     */
    public function tick(
        AiPreset $preset,
        ?string $globalSuspendFlag = null,
        int $minIntervalSeconds = 0,
    ): TickResult {
        $now      = now();
        $lastTick = $this->runtime->lastTickAt($preset);

        // dt = seconds since last tick. NOTE: after long downtime dt is large and
        // ACC will jump toward its cap in one step. That is faithful to "weight·dt"
        // (the quantity did accumulate over real time). If you'd rather bound it,
        // clamp dt here — left unclamped on purpose for transparency.
        $dt = $lastTick !== null ? (int) abs($now->diffInSeconds($lastTick)) : 0;

        if ($minIntervalSeconds > 0 && $lastTick !== null && $dt < $minIntervalSeconds) {
            return TickResult::skipped($dt);
        }

        $active = $this->contracts->ofStatus($preset, ContractStatus::ACTIVE);

        $buckets = [
            'fired' => [], 'cleared' => [], 'held' => [],
            'idle' => [], 'suspended' => [], 'unsatisfiable' => [],
        ];

        foreach ($active as $contract) {
            $outcome = $this->evaluate($contract, $preset, $dt, $globalSuspendFlag, $now);
            $buckets[$outcome][] = $contract->name;
        }

        $result = new TickResult(
            evaluated:     count($active),
            dtSeconds:     $dt,
            skipped:       false,
            fired:         $buckets['fired'],
            cleared:       $buckets['cleared'],
            held:          $buckets['held'],
            idle:          $buckets['idle'],
            suspended:     $buckets['suspended'],
            unsatisfiable: $buckets['unsatisfiable'],
        );

        if (!empty($buckets['fired']) || !empty($buckets['cleared'])) {
            $this->logger->info('ContractEngine: ' . $result->summary(), [
                'preset_id' => $preset->getId(),
                'fired'     => $buckets['fired'],
                'cleared'   => $buckets['cleared'],
            ]);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Evaluation
    // -------------------------------------------------------------------------

    /**
     * Evaluate one contract; return the bucket name it lands in.
     */
    private function evaluate(
        ContractDefinition $c,
        AiPreset $preset,
        int $dt,
        ?string $globalSuspendFlag,
        \Illuminate\Support\Carbon $now,
    ): string {
        // ── Auto-suspend (per-tick skip, not a status change) ────────────────
        if ($c->suspendWhen !== null && $this->runtime->isFlagRaised($preset, $c->suspendWhen)) {
            return 'suspended';
        }
        if ($globalSuspendFlag !== null
            && $globalSuspendFlag !== ''
            && $this->runtime->isFlagRaised($preset, $globalSuspendFlag)) {
            return 'suspended';
        }

        // ── Compute satisfiability + whether the condition is met ────────────
        [$satisfiable, $triggered] = $this->computeTriggered($c, $preset, $dt);

        if (!$satisfiable) {
            return 'unsatisfiable';
        }

        $previous = $this->runtime->previousTriggered($preset, $c->name);

        // ── Edge handling ────────────────────────────────────────────────────
        if ($triggered && !$previous) {
            $this->fireAction($c, $preset);
            $this->runtime->recordEvaluation($preset, $c->name, true, true, $now);
            return 'fired';
        }

        if (!$triggered && $previous) {
            $this->runtime->recordEvaluation($preset, $c->name, false, false, $now);
            return 'cleared';
        }

        // Held or idle — still record so last_evaluated_at (and dt) stay fresh.
        $this->runtime->recordEvaluation($preset, $c->name, $triggered, false, $now);
        return $triggered ? 'held' : 'idle';
    }

    /**
     * @return array{0: bool, 1: bool}  [satisfiable, triggered]
     */
    private function computeTriggered(ContractDefinition $c, AiPreset $preset, int $dt): array
    {
        switch ($c->form) {
            case ContractForm::THR_T:
                $reader = $c->match ? $this->readers->forSource($c->match->source) : null;
                if ($reader === null) {
                    return [false, false];
                }
                $secs = $reader->secondsSinceLast($preset, $c->match);
                $threshold = (int) ($c->trigger['threshold_seconds'] ?? 0);
                return [true, $secs !== null && $secs >= $threshold];

            case ContractForm::THR_C:
                $reader = $c->match ? $this->readers->forSource($c->match->source) : null;
                if ($reader === null) {
                    return [false, false];
                }
                $window = isset($c->trigger['window_seconds'])
                    ? (int) $c->trigger['window_seconds']
                    : null;
                $count = $reader->count($preset, $c->match, $window);
                $threshold = (int) ($c->trigger['threshold_count'] ?? 0);
                return [true, $count >= $threshold];

            case ContractForm::ACC:
                if (!$this->vector->isAvailable($preset)) {
                    return [false, false];
                }
                $target = (string) ($c->trigger['target'] ?? '');
                $weight = (float) ($c->trigger['weight'] ?? 0.0);
                $cap    = (float) ($c->trigger['cap'] ?? 1.0);
                $value  = min($cap, $this->vector->get($preset, $target, 0.0) + $weight * $dt);
                $this->vector->set($preset, $target, $value);
                return [true, $value >= $cap];

            case ContractForm::DEC:
                if (!$this->vector->isAvailable($preset)) {
                    return [false, false];
                }
                $target = (string) ($c->trigger['target'] ?? '');
                $rate   = (float) ($c->trigger['rate'] ?? 0.0);
                $floor  = (float) ($c->trigger['floor'] ?? 0.0);
                // Per-tick (discrete), per spec — not dt-scaled. Mirrors mood beat.
                $value  = max($floor, $this->vector->get($preset, $target, 0.0) - $rate);
                $this->vector->set($preset, $target, $value);
                return [true, $value <= $floor];
        }

        return [false, false];
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Run a contract's action on the rising edge.
     *
     * set_flag / create_goal do nothing here: the flag is derived from the
     * `triggered` state recorded by the caller. Only nudge_state and inject_memo
     * have side effects to perform.
     */
    private function fireAction(ContractDefinition $c, AiPreset $preset): void
    {
        $type = (string) ($c->action['type'] ?? '');

        switch ($type) {
            case 'set_flag':
            case 'create_goal':
                // Derived from `triggered`; nothing to write.
                break;

            case 'nudge_state':
                if (!$this->vector->isAvailable($preset)) {
                    break;
                }
                $target = (string) ($c->action['target'] ?? '');
                $delta  = (float) ($c->action['delta'] ?? 0.0);
                if ($target !== '') {
                    $this->vector->set(
                        $preset,
                        $target,
                        $this->vector->get($preset, $target, 0.0) + $delta,
                    );
                }
                break;

            case 'inject_memo':
                $text = (string) ($c->action['text'] ?? '');
                if ($text === '') {
                    // No explicit text: a contract whose purpose is the SIGNAL, not a
                    // specific message, still injects something legible rather than a
                    // silent no-op. Mirrors the "nothing is lost silently" discipline
                    // already applied to the missing-writer branch below.
                    $text = "Contract '{$c->name}' triggered.";
                }
                if ($this->memo !== null) {
                    $this->memo->write($preset, $text);
                } else {
                    // Degrade rather than fail: nothing is lost silently.
                    $this->logger->warning(
                        'ContractEngine: inject_memo fired but no memo writer is bound',
                        ['preset_id' => $preset->getId(), 'contract' => $c->name],
                    );
                }
                break;

            default:
                $this->logger->warning('ContractEngine: unknown action type', [
                    'preset_id' => $preset->getId(),
                    'contract'  => $c->name,
                    'type'      => $type,
                ]);
        }
    }
}

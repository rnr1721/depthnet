<?php

namespace App\Services\Agent\Behavior\Triggers;

use App\Contracts\Agent\Behavior\TriggerEvaluatorInterface;
use App\Contracts\Agent\PulseServiceInterface;
use App\Models\AiPreset;

/**
 * PulseTriggerEvaluator — fires when the current pulse (position within the
 * subjective day, 0..PULSES_PER_DAY) falls inside a range.
 *
 * IMPORTANT — pulse here is a TRIGGER, not the decay axis. Its wall-clock
 * cyclicity (it wraps at midnight) is harmless for "is pulse within [a,b]?" —
 * we never take a difference. The decay axis remains cycle_seq, unconditionally,
 * precisely because pulse can't be subtracted safely and may be disabled.
 *
 * This evaluator is itself optional: pulse is a feature, not a guarantee. When a
 * pattern uses a pulse trigger but the preset has no subjective-time config, the
 * trigger never fires. A pulse-gated pattern in a plain agent install simply
 * stays dormant — correct, not an error.
 *
 * Trigger JSON shape (wrap-around supported when from > to, e.g. night 950→50):
 *   { "kind": "pulse", "from": 0, "to": 200 }
 */
final class PulseTriggerEvaluator implements TriggerEvaluatorInterface
{
    public function __construct(
        protected PulseServiceInterface $pulse,
    ) {
    }

    public function kind(): string
    {
        return 'pulse';
    }

    public function isSatisfied(AiPreset $preset, array $trigger): bool
    {
        // Pulse trigger is only meaningful when the preset opts into pulse dates.
        // getPulseDates() already exists on AiPreset; honour it so pulse stays a
        // feature, not a silent global.
        if (!$preset->getPulseDates()) {
            return false;
        }

        if (!isset($trigger['from'], $trigger['to'])) {
            return false;
        }

        $from = (int) $trigger['from'];
        $to   = (int) $trigger['to'];
        $now  = $this->pulse->currentPulse();

        // Normal range [from..to]; wrap-around range (from > to) spans midnight.
        if ($from <= $to) {
            return $now >= $from && $now <= $to;
        }

        return $now >= $from || $now <= $to;
    }
}

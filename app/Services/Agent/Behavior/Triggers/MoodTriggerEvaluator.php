<?php

namespace App\Services\Agent\Behavior\Triggers;

use App\Contracts\Agent\Behavior\TriggerEvaluatorInterface;
use App\Contracts\Agent\Contract\StateVectorInterface;
use App\Models\AiPreset;

/**
 * MoodTriggerEvaluator — fires on an emotional-state-vector dimension crossing a
 * threshold.
 *
 * Reads through the SAME StateVectorInterface the contract engine uses, so it
 * transparently sees MoodPlugin's states via MoodStateVectorAdapter — or reads
 * empty through NullStateVector when no provider is bound. No new coupling to
 * mood: ABS depends on the interface, not the plugin. When the vector is
 * unavailable, the trigger simply never fires (a pattern gated on a dimension
 * that doesn't exist can't win — correct, not an error).
 *
 * Trigger JSON shape:
 *   { "kind": "mood", "target": "focus", "op": ">", "value": 0.5 }
 *
 * Supported ops: >  >=  <  <=  ==  !=
 */
final class MoodTriggerEvaluator implements TriggerEvaluatorInterface
{
    public function __construct(
        protected StateVectorInterface $vector,
    ) {
    }

    public function kind(): string
    {
        return 'mood';
    }

    public function isSatisfied(AiPreset $preset, array $trigger): bool
    {
        if (!$this->vector->isAvailable($preset)) {
            return false;
        }

        $target = (string) ($trigger['target'] ?? '');
        if ($target === '') {
            return false;
        }

        $op       = (string) ($trigger['op'] ?? '>');
        $threshold = (float) ($trigger['value'] ?? 0.0);
        $actual   = $this->vector->get($preset, $target, 0.0);

        return match ($op) {
            '>'  => $actual >  $threshold,
            '>=' => $actual >= $threshold,
            '<'  => $actual <  $threshold,
            '<=' => $actual <= $threshold,
            '==' => abs($actual - $threshold) < 1e-9,
            '!=' => abs($actual - $threshold) >= 1e-9,
            default => false,
        };
    }
}

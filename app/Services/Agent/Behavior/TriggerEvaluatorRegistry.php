<?php

namespace App\Services\Agent\Behavior;

use App\Contracts\Agent\Behavior\TriggerEvaluatorInterface;

/**
 * TriggerEvaluatorRegistry — maps a trigger's "kind" to the evaluator that
 * serves it. Direct copy of TraceReaderRegistry's pattern.
 *
 * Evaluators arrive via a tagged binding (see service provider). An unknown kind
 * returns null; the selector treats a pattern whose trigger has no evaluator as
 * NON-activating (it can never win) and logs once — a pattern you can't evaluate
 * must not silently win or silently vanish.
 */
class TriggerEvaluatorRegistry
{
    /** @var array<string, TriggerEvaluatorInterface> */
    private array $byKind = [];

    /**
     * @param iterable<TriggerEvaluatorInterface> $evaluators
     */
    public function __construct(iterable $evaluators = [])
    {
        foreach ($evaluators as $evaluator) {
            $this->register($evaluator);
        }
    }

    public function register(TriggerEvaluatorInterface $evaluator): void
    {
        $this->byKind[$evaluator->kind()] = $evaluator;
    }

    public function forKind(string $kind): ?TriggerEvaluatorInterface
    {
        return $this->byKind[$kind] ?? null;
    }

    /** @return string[] kinds with a registered evaluator */
    public function kinds(): array
    {
        return array_keys($this->byKind);
    }
}

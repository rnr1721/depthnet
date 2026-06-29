<?php

namespace App\Contracts\Agent\Behavior;

use App\Models\AiPreset;

/**
 * TriggerEvaluatorInterface — answers "is this trigger satisfied right now?" for
 * ONE kind of structural trigger.
 *
 * Phase 1 triggers are STRUCTURAL and code-evaluated — no LLM. A semantic
 * (LLM-judged) trigger would smuggle the model back into the core loop as a
 * perception layer; deliberately excluded while structural triggers suffice
 * (ABS phase-1 doc, point 6).
 *
 * Mirrors the TraceReaderInterface discipline: one implementation per kind,
 * resolved by kind() via a registry, supplied through a tagged binding. A new
 * trigger kind is a new class + one binding — the selector never changes.
 *
 * Evaluators are READ-ONLY: they inspect state (mood vector, pulse, flags) and
 * return a bool. They must not mutate anything — evaluation happens before the
 * generation pass and may run over many patterns per cycle.
 */
interface TriggerEvaluatorInterface
{
    /**
     * The trigger kind this evaluator handles, e.g. 'mood', 'pulse', 'flag'.
     * Matched against the trigger JSON's "kind" field.
     */
    public function kind(): string;

    /**
     * Is the trigger satisfied for this preset right now?
     *
     * @param array $trigger The pattern's trigger JSON (shape varies by kind).
     *                       Guaranteed to carry "kind" === $this->kind().
     */
    public function isSatisfied(AiPreset $preset, array $trigger): bool;
}

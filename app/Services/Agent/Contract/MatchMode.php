<?php

namespace App\Services\Agent\Contract;

/**
 * How a contract's `contains` text match is resolved.
 *
 * The mode is part of the contract definition and visible on read — never a
 * silent fallback inside the engine. A silent escalation from exact to "smart"
 * would make a contract behave differently on similar inputs with no trace in
 * its definition, destroying the predictability the contract exists to provide.
 *
 *   strict               — substring match on summary only. Fully deterministic.
 *   semantic             — TF-IDF / embedding search. Probabilistic by declaration.
 *   strict_then_semantic — strict first; if zero matches, supplement with semantic.
 *                          Hybrid, but declared.
 *
 * Rule: only `strict` is eligible to back a vital contract. A safety interlock
 * cannot rest on a probabilistic match.
 */
enum MatchMode: string
{
    case STRICT               = 'strict';
    case SEMANTIC             = 'semantic';
    case STRICT_THEN_SEMANTIC = 'strict_then_semantic';

    /** Whether a contract using this mode may be marked vital. */
    public function vitalEligible(): bool
    {
        return $this === self::STRICT;
    }

    /** Whether this mode performs any semantic (non-deterministic) matching. */
    public function usesSemantic(): bool
    {
        return $this !== self::STRICT;
    }
}

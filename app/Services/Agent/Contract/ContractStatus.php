<?php

namespace App\Services\Agent\Contract;

/**
 * Contract lifecycle status.
 *
 *   hypothesis → active → suspended
 *        ↑__________|__________|
 *
 *   hypothesis — defined but not executing. Observed, not yet trusted.
 *   active     — executing every tick.
 *   suspended  — temporarily halted, definition and history preserved.
 *
 * Reversibility is first-class: a contract that stops fitting returns to
 * hypothesis (revoke) rather than being deleted.
 */
enum ContractStatus: string
{
    case HYPOTHESIS = 'hypothesis';
    case ACTIVE     = 'active';
    case SUSPENDED  = 'suspended';

    /** Only active contracts are evaluated on a tick. */
    public function isExecuting(): bool
    {
        return $this === self::ACTIVE;
    }
}

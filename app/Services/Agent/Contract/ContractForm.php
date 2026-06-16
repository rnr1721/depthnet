<?php

namespace App\Services\Agent\Contract;

/**
 * The four contract forms. Closed set.
 *
 * A contract is exactly one of these. If a rule cannot be expressed as one
 * of them, it is not a contract — it belongs in the agent's reasoning.
 *
 *   THR_T — time threshold:   ≥ N seconds since the most recent matching trace
 *   THR_C — counter threshold: ≥ N matching traces within a window
 *   ACC   — accumulation:      value += weight · dt, capped
 *   DEC   — decay:             value -= rate per tick, floored
 *
 * THR_T / THR_C carry a ContractMatch (which trace to look at).
 * ACC / DEC carry a state-vector target (which scalar to move).
 */
enum ContractForm: string
{
    case THR_T = 'THR_T';
    case THR_C = 'THR_C';
    case ACC   = 'ACC';
    case DEC   = 'DEC';

    /** Whether this form reads a trace via a ContractMatch (vs. a state-vector target). */
    public function usesMatch(): bool
    {
        return $this === self::THR_T || $this === self::THR_C;
    }

    /** Whether this form reads/writes the state vector. */
    public function usesStateVector(): bool
    {
        return $this === self::ACC || $this === self::DEC;
    }
}

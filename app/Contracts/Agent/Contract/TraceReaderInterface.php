<?php

namespace App\Contracts\Agent\Contract;

use App\Models\AiPreset;
use App\Services\Agent\Contract\ContractMatch;

/**
 * TraceReaderInterface — answers the two questions THR_C and THR_T ask about an
 * existing trace, without the engine knowing how the trace is stored.
 *
 * The engine never adds an event log: each reader queries data that is already
 * written (journal entries, heart signals, vectormemory timestamps, chat
 * messages). A reader declares which `source` string it serves; the registry
 * routes a contract's match to the right reader.
 *
 * For now only the journal source is implemented. New sources are new readers —
 * the engine and the contract shape do not change.
 */
interface TraceReaderInterface
{
    /**
     * Which match.source value this reader serves (e.g. 'journal').
     */
    public function source(): string;

    /**
     * Number of traces matching `$match` within the last `$windowSeconds`.
     * A null window means "all time".  Used by THR_C.
     */
    public function count(AiPreset $preset, ContractMatch $match, ?int $windowSeconds): int;

    /**
     * Whole seconds since the most recent trace matching `$match`, or null if no
     * matching trace exists.  Used by THR_T.
     */
    public function secondsSinceLast(AiPreset $preset, ContractMatch $match): ?int;
}

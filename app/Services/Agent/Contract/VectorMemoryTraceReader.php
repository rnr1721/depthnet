<?php

namespace App\Services\Agent\Contract;

use App\Contracts\Agent\Contract\TraceReaderInterface;
use App\Models\AiPreset;
use App\Models\VectorMemory;
use Illuminate\Support\Carbon;

/**
 * VectorMemoryTraceReader — answers THR_C / THR_T over crystallized memory.
 *
 * The journal records *what happened*; vector memory records *what was
 * crystallized* — insights, snapshots, distilled reasoning. A contract that
 * watches "how long since I last crystallized anything" must look here, not at
 * the journal, because crystallization lands in vector memory by design.
 *
 * This is the second TraceReader (after JournalTraceReader) and exists to prove
 * the registry pattern: a new source is a new reader, and neither the engine nor
 * the contract shape changes — only this class and one tagged binding.
 *
 * Match semantics for source 'vectormemory':
 *   - `type`    → interpreted as DOMAIN (vector memory has no type column, but it
 *                 has domains; mapping `type`→domain lets the canonical match
 *                 shape address them without a new field).
 *   - `contains`→ substring on `content` (strict only; vector memory has its own
 *                 semantic search, but a contract over "did I crystallize X" wants
 *                 the deterministic path — semantic counting here would be doubly
 *                 probabilistic and is intentionally not offered).
 *   - `outcome` → ignored (no such column); left harmless if present.
 *
 * Time is first-tier here: created_at is written by the system when a record is
 * stored, not by a separate agent "log" act — so a vectormemory THR_T is sound
 * and may back a vital contract. Count is likewise system-written (one row per
 * crystallization), making it more trustworthy than a journal count, though the
 * spec still treats only time as first-tier across sources for safety.
 */
final class VectorMemoryTraceReader implements TraceReaderInterface
{
    public function __construct(
        protected VectorMemory $model,
    ) {
    }

    public function source(): string
    {
        return 'vectormemory';
    }

    public function count(AiPreset $preset, ContractMatch $match, ?int $windowSeconds): int
    {
        return $this->baseQuery($preset, $match, $windowSeconds)->count();
    }

    public function secondsSinceLast(AiPreset $preset, ContractMatch $match): ?int
    {
        // "Since last" looks across all time — no window.
        $latest = $this->baseQuery($preset, $match, null)
            ->max('created_at');

        if ($latest === null) {
            return null;
        }

        $latest = $latest instanceof Carbon ? $latest : Carbon::parse($latest);

        return (int) abs(now()->diffInSeconds($latest));
    }

    // -------------------------------------------------------------------------

    /**
     * Build the filtered query shared by count() and secondsSinceLast().
     */
    private function baseQuery(AiPreset $preset, ContractMatch $match, ?int $windowSeconds)
    {
        $query = $this->model->newQuery()->forPreset($preset->getId());

        // type → domain (see class docblock).
        if ($match->type !== null && $match->type !== '') {
            $query->where('domain', $match->type);
        }

        // contains → deterministic substring on content.
        if ($match->contains !== null && $match->contains !== '') {
            $needle = addcslashes($match->contains, '%_\\');
            $query->where('content', 'like', '%' . $needle . '%');
        }

        if ($windowSeconds !== null) {
            $query->where('created_at', '>=', now()->subSeconds($windowSeconds));
        }

        return $query;
    }
}

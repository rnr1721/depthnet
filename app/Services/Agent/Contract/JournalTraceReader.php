<?php

namespace App\Services\Agent\Contract;

use App\Contracts\Agent\Contract\TraceReaderInterface;
use App\Contracts\Agent\Journal\JournalServiceInterface;
use App\Models\AiPreset;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;

/**
 * JournalTraceReader — resolves a journal `match` into matching entries, then
 * answers count (THR_C) and time-since-last (THR_T) over them.
 *
 * The journal schema maps onto ready scopes:
 *   type     → ofType        (action|reflection|decision|error|observation|interaction)
 *   outcome  → withOutcome    (success|failure|pending)
 *   window   → between        (from the form's threshold)
 *   contains → resolved per MatchMode (see resolve()).
 *
 * Determinism note: only match_mode = strict is fully deterministic (a LIKE on
 * summary). Semantic modes route through JournalService::searchEntries (TF-IDF /
 * embedding), which is ranked and capped — so a semantic count is approximate.
 * This is exactly why semantic contracts may not be vital (enforced upstream in
 * ContractDefinition::validate()). The cap is SEMANTIC_SCAN_LIMIT below.
 */
final class JournalTraceReader implements TraceReaderInterface
{
    /** Upper bound on entries pulled for a semantic match (approximate count). */
    private const SEMANTIC_SCAN_LIMIT = 100;

    public function __construct(
        protected JournalEntry             $model,
        protected JournalServiceInterface  $journal,
    ) {
    }

    public function source(): string
    {
        return 'journal';
    }

    public function count(AiPreset $preset, ContractMatch $match, ?int $windowSeconds): int
    {
        return count($this->resolve($preset, $match, $windowSeconds));
    }

    public function secondsSinceLast(AiPreset $preset, ContractMatch $match): ?int
    {
        // "Since last" looks across all time — no window.
        $entries = $this->resolve($preset, $match, null);

        if (empty($entries)) {
            return null;
        }

        $latest = null;
        foreach ($entries as $entry) {
            $ts = $entry->recorded_at;
            if ($ts !== null && ($latest === null || $ts->gt($latest))) {
                $latest = $ts;
            }
        }

        if ($latest === null) {
            return null;
        }

        return (int) abs(now()->diffInSeconds($latest));
    }

    // -------------------------------------------------------------------------
    // Resolution
    // -------------------------------------------------------------------------

    /**
     * Return the journal entries matching `$match` (optionally within a window).
     *
     * @return JournalEntry[]
     */
    private function resolve(AiPreset $preset, ContractMatch $match, ?int $windowSeconds): array
    {
        $mode     = $match->matchMode->value;
        $contains = $match->contains;

        // strict_then_semantic: try strict first, fall back to semantic only on zero.
        if ($mode === 'strict_then_semantic') {
            $strict = $this->resolveStrict($preset, $match, $windowSeconds);
            if (!empty($strict)) {
                return $strict;
            }
            return $this->resolveSemantic($preset, $match, $windowSeconds);
        }

        if ($mode === 'semantic' && $contains !== null && $contains !== '') {
            return $this->resolveSemantic($preset, $match, $windowSeconds);
        }

        // strict (or any mode with no contains text → pure structured query).
        return $this->resolveStrict($preset, $match, $windowSeconds);
    }

    /**
     * Deterministic path: structured scopes + LIKE on summary.
     *
     * @return JournalEntry[]
     */
    private function resolveStrict(AiPreset $preset, ContractMatch $match, ?int $windowSeconds): array
    {
        $query = $this->model->forPreset($preset->getId());

        if ($match->type !== null) {
            $query->ofType($match->type);
        }
        if ($match->outcome !== null) {
            $query->withOutcome($match->outcome);
        }
        if ($windowSeconds !== null) {
            $query->between(now()->subSeconds($windowSeconds), now());
        }
        if ($match->contains !== null && $match->contains !== '') {
            // LIKE with the literal escaped so % and _ in the text aren't wildcards.
            $needle = addcslashes($match->contains, '%_\\');
            $query->where('summary', 'like', '%' . $needle . '%');
        }

        return $query->orderByDesc('recorded_at')->get()->all();
    }

    /**
     * Probabilistic path: reuse the journal's semantic search, then post-filter
     * by the structured fields (type / outcome / window) it doesn't apply.
     *
     * @return JournalEntry[]
     */
    private function resolveSemantic(AiPreset $preset, ContractMatch $match, ?int $windowSeconds): array
    {
        if ($match->contains === null || $match->contains === '') {
            // Nothing to search semantically — degrade to structured query.
            return $this->resolveStrict($preset, $match, $windowSeconds);
        }

        $candidates = $this->journal->searchEntries(
            $preset,
            $match->contains,
            self::SEMANTIC_SCAN_LIMIT,
        );

        $from = $windowSeconds !== null ? now()->subSeconds($windowSeconds) : null;

        return array_values(array_filter($candidates, function (JournalEntry $e) use ($match, $from) {
            if ($match->type !== null && $e->type !== $match->type) {
                return false;
            }
            if ($match->outcome !== null && $e->outcome !== $match->outcome) {
                return false;
            }
            if ($from !== null && ($e->recorded_at === null || $e->recorded_at->lt($from))) {
                return false;
            }
            return true;
        }));
    }
}

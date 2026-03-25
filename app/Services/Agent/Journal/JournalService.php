<?php

namespace App\Services\Agent\Journal;

use App\Contracts\Agent\Journal\JournalServiceInterface;
use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use App\Models\AiPreset;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * JournalService
 *
 * Episodic memory for AI agents — a chronological, semantically searchable
 * chronicle of events. Unlike VectorMemory (crystallized insights),
 * the journal records *what happened*: actions, decisions, errors,
 * interactions, reflections.
 *
 * Search modes:
 *   - Semantic only:       "worked on database"
 *   - Date only:           "2024-03-15" / "yesterday" / "last week"
 *   - Date + semantic:     "2024-03-15 | worked on database"
 */
class JournalService implements JournalServiceInterface
{
    /**
     * Valid event types.
     */
    public const TYPES = ['action', 'reflection', 'decision', 'error', 'observation', 'interaction'];

    /**
     * Valid outcomes.
     */
    public const OUTCOMES = ['success', 'failure', 'pending'];

    public function __construct(
        protected TfIdfServiceInterface $tfIdfService,
        protected JournalEntry          $journalModel,
        protected LoggerInterface       $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Add a new journal entry.
     *
     * Format: "type | summary" or "type | summary | details"
     * Outcome can be appended: "type | summary | details | outcome:success"
     *
     * Examples:
     *   [journal]action | Refactored memory plugin[/journal]
     *   [journal]error | DB connection failed | Timeout after 30s | outcome:failure[/journal]
     *   [journal]decision | Chose approach A over B | Because A is simpler[/journal]
     */
    public function addEntry(AiPreset $preset, string $content): array
    {
        try {
            $content = trim($content);
            if (empty($content)) {
                return ['success' => false, 'message' => 'Journal entry cannot be empty.'];
            }

            $parsed = $this->parseEntryContent($content);

            $vector = $this->tfIdfService->vectorize(
                $parsed['summary'] . ' ' . ($parsed['details'] ?? '')
            );

            $entry = $this->journalModel->create([
                'preset_id'    => $preset->id,
                'recorded_at'  => now(),
                'type'         => $parsed['type'],
                'summary'      => $parsed['summary'],
                'details'      => $parsed['details'],
                'outcome'      => $parsed['outcome'],
                'tfidf_vector' => $vector,
            ]);

            $outcomeStr = $entry->outcome ? " [{$entry->outcome}]" : '';
            return [
                'success' => true,
                'message' => "Journal entry #{$entry->id} recorded [{$entry->type}]{$outcomeStr}: {$entry->summary}",
                'entry'   => $entry,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('JournalService::addEntry error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error recording journal entry: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Get recent entries.
     */
    public function recent(AiPreset $preset, int $limit = 10): array
    {
        try {
            $limit   = max(1, min(50, $limit));
            $entries = $this->journalModel
                ->forPreset($preset->id)
                ->recent($limit)
                ->get();

            if ($entries->isEmpty()) {
                return ['success' => true, 'message' => 'Journal is empty.'];
            }

            return ['success' => true, 'message' => $this->formatEntries($entries->all(), "Recent {$limit} journal entries")];

        } catch (\Throwable $e) {
            $this->logger->error('JournalService::recent error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error reading journal: ' . $e->getMessage()];
        }
    }

    /**
     * Show full details of a single entry.
     */
    public function show(AiPreset $preset, int $id): array
    {
        try {
            $entry = $this->journalModel->forPreset($preset->id)->find($id);

            if (!$entry) {
                return ['success' => false, 'message' => "Journal entry #{$id} not found."];
            }

            return ['success' => true, 'message' => $this->formatEntryFull($entry)];

        } catch (\Throwable $e) {
            $this->logger->error('JournalService::show error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error reading entry: ' . $e->getMessage()];
        }
    }

    /**
     * Semantic search — finds entries by meaning.
     * Optionally filtered to a date or date range.
     *
     * Query formats:
     *   "worked on memory"                     — semantic only
     *   "2024-03-15 | worked on memory"        — date + semantic
     *   "yesterday | worked on memory"          — relative date + semantic
     *   "2024-03-10:2024-03-15 | memory"       — date range + semantic
     */
    public function search(AiPreset $preset, string $query, int $limit = 10): array
    {
        try {
            [$dateFilter, $semanticQuery] = $this->parseSearchQuery($query);

            $dbQuery = $this->journalModel->forPreset($preset->id);

            // Apply date filter if present
            if ($dateFilter) {
                if (isset($dateFilter['from'], $dateFilter['to'])) {
                    $dbQuery->between($dateFilter['from'], $dateFilter['to']);
                } elseif (isset($dateFilter['date'])) {
                    $dbQuery->onDate($dateFilter['date']);
                }
            }

            $entries = $dbQuery->orderBy('recorded_at', 'desc')->get();

            if ($entries->isEmpty()) {
                $dateStr = $dateFilter ? ' for the specified date' : '';
                return ['success' => true, 'message' => "No journal entries found{$dateStr}."];
            }

            // If we have a semantic query, run TF-IDF on the filtered set
            if (!empty($semanticQuery)) {
                $results = $this->tfIdfService->findSimilar(
                    $semanticQuery,
                    $entries,
                    $limit,
                    0.05,
                    false // date is already our recency signal
                );

                if (empty($results)) {
                    return ['success' => true, 'message' => "No entries matching \"{$semanticQuery}\" found."];
                }

                $matched = array_map(fn ($r) => $r['document'], $results);
                $header  = "Journal search: \"{$semanticQuery}\"" . ($dateFilter ? ' (date filtered)' : '');
                return ['success' => true, 'message' => $this->formatEntries($matched, $header)];
            }

            // Date-only: return chronological results
            $limited = $entries->take($limit)->all();
            $header  = $dateFilter ? 'Journal entries for ' . $this->describeDateFilter($dateFilter) : "Journal entries";
            return ['success' => true, 'message' => $this->formatEntries($limited, $header)];

        } catch (\Throwable $e) {
            $this->logger->error('JournalService::search error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error searching journal: ' . $e->getMessage()];
        }
    }

    /**
     * Search and return raw JournalEntry objects — used by RAG enricher.
     * Same logic as search() but returns models, not formatted text.
     *
     * @return \App\Models\JournalEntry[]
     */
    public function searchEntries(AiPreset $preset, string $query, int $limit = 3): array
    {
        try {
            [$dateFilter, $semanticQuery] = $this->parseSearchQuery($query);
            $dbQuery = $this->journalModel->forPreset($preset->id);
            if ($dateFilter) {
                if (isset($dateFilter['from'], $dateFilter['to'])) {
                    $dbQuery->between($dateFilter['from'], $dateFilter['to']);
                } elseif (isset($dateFilter['date'])) {
                    $dbQuery->onDate($dateFilter['date']);
                }
            }
            $entries = $dbQuery->orderBy('recorded_at', 'desc')->get();
            if ($entries->isEmpty()) {
                return [];
            }
            if (!empty($semanticQuery)) {
                $results = $this->tfIdfService->findSimilar($semanticQuery, $entries, $limit, 0.05, false);
                return array_map(fn ($r) => $r['document'], $results);
            }
            return $entries->take($limit)->all();
        } catch (\Throwable $e) {
            $this->logger->error('JournalService::searchEntries error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete a single entry by ID.
     */
    public function delete(AiPreset $preset, int $id): array
    {
        try {
            $deleted = $this->journalModel->forPreset($preset->id)->where('id', $id)->delete();

            if (!$deleted) {
                return ['success' => false, 'message' => "Journal entry #{$id} not found."];
            }

            return ['success' => true, 'message' => "Journal entry #{$id} deleted."];

        } catch (\Throwable $e) {
            $this->logger->error('JournalService::delete error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting entry: ' . $e->getMessage()];
        }
    }

    /**
     * Clear all journal entries for a preset.
     */
    public function clear(AiPreset $preset): array
    {
        try {
            $count = $this->journalModel->forPreset($preset->id)->count();
            $this->journalModel->forPreset($preset->id)->delete();

            return ['success' => true, 'message' => "Journal cleared. {$count} entries removed."];

        } catch (\Throwable $e) {
            $this->logger->error('JournalService::clear error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error clearing journal: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Parsing helpers
    // -------------------------------------------------------------------------

    /**
     * Parse entry content into structured parts.
     *
     * Formats:
     *   "summary"
     *   "type | summary"
     *   "type | summary | details"
     *   "type | summary | details | outcome:value"
     */
    protected function parseEntryContent(string $content): array
    {
        $parts = array_map('trim', explode('|', $content));

        $type    = 'observation';
        $summary = '';
        $details = null;
        $outcome = null;

        if (count($parts) === 1) {
            // Just a summary
            $summary = $parts[0];
        } elseif (in_array(strtolower($parts[0]), self::TYPES)) {
            // First part is a valid type
            $type    = strtolower(array_shift($parts));
            $summary = array_shift($parts) ?? '';
            // Remaining parts
            foreach ($parts as $part) {
                if (str_starts_with(strtolower($part), 'outcome:')) {
                    $outcomeVal = strtolower(substr($part, 8));
                    if (in_array($outcomeVal, self::OUTCOMES)) {
                        $outcome = $outcomeVal;
                    }
                } elseif ($details === null) {
                    $details = $part;
                } else {
                    $details .= "\n" . $part;
                }
            }
        } else {
            // No type prefix — treat all as summary + details
            $summary = array_shift($parts);
            if (!empty($parts)) {
                $details = implode("\n", $parts);
            }
        }

        return compact('type', 'summary', 'details', 'outcome');
    }

    /**
     * Parse search query into [dateFilter, semanticQuery].
     *
     * Supported date formats (before the "|"):
     *   2024-03-15              — specific date
     *   2024-03-10:2024-03-15   — date range
     *   yesterday               — relative
     *   today                   — relative
     *   last week               — relative (last 7 days)
     *   this week               — relative (current week)
     *   last month              — relative (last 30 days)
     *
     * @return array{0: array|null, 1: string}
     */
    protected function parseSearchQuery(string $query): array
    {
        $query = trim($query);

        // Check for "date | semantic" format
        if (str_contains($query, '|')) {
            [$datePart, $semanticPart] = array_map('trim', explode('|', $query, 2));
            $dateFilter = $this->parseDateExpression($datePart);
            if ($dateFilter !== null) {
                return [$dateFilter, $semanticPart];
            }
        }

        // Try to parse the whole query as a date expression
        $dateFilter = $this->parseDateExpression($query);
        if ($dateFilter !== null) {
            return [$dateFilter, ''];
        }

        // Pure semantic query
        return [null, $query];
    }

    /**
     * Parse a date expression into a filter array.
     * Returns null if the expression is not recognizable as a date.
     */
    protected function parseDateExpression(string $expr): ?array
    {
        $expr = trim(strtolower($expr));

        // Date range: "2024-03-10:2024-03-15"
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*:\s*(\d{4}-\d{2}-\d{2})$/', $expr, $m)) {
            return [
                'from' => Carbon::parse($m[1])->startOfDay(),
                'to'   => Carbon::parse($m[2])->endOfDay(),
            ];
        }

        // Specific date: "2024-03-15"
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expr)) {
            return ['date' => Carbon::parse($expr)];
        }

        // Relative expressions
        return match ($expr) {
            'today'      => ['date' => Carbon::today()],
            'yesterday'  => ['date' => Carbon::yesterday()],
            'last week', 'week'  => [
                'from' => Carbon::now()->subDays(7)->startOfDay(),
                'to'   => Carbon::now()->endOfDay(),
            ],
            'this week'  => [
                'from' => Carbon::now()->startOfWeek()->startOfDay(),
                'to'   => Carbon::now()->endOfDay(),
            ],
            'last month', 'month' => [
                'from' => Carbon::now()->subDays(30)->startOfDay(),
                'to'   => Carbon::now()->endOfDay(),
            ],
            default => null,
        };
    }

    // -------------------------------------------------------------------------
    // Formatting helpers
    // -------------------------------------------------------------------------

    /**
     * Format a list of entries as a compact summary block.
     *
     * @param JournalEntry[] $entries
     */
    protected function formatEntries(array $entries, string $header): string
    {
        $lines = ["[JOURNAL: {$header}]", ''];

        foreach ($entries as $entry) {
            $date    = $entry->recorded_at->format('Y-m-d H:i');
            $outcome = $entry->outcome ? " [{$entry->outcome}]" : '';
            $lines[] = "#{$entry->id} [{$date}] [{$entry->type}]{$outcome} {$entry->summary}";
        }

        $lines[] = '';
        $lines[] = '[END JOURNAL]';

        return implode("\n", $lines);
    }

    /**
     * Format a single entry with full details.
     */
    protected function formatEntryFull(JournalEntry $entry): string
    {
        $lines = [
            "Journal Entry #{$entry->id}",
            "Date:    " . $entry->recorded_at->format('Y-m-d H:i:s'),
            "Type:    " . $entry->type,
            "Outcome: " . ($entry->outcome ?? '—'),
            "Summary: " . $entry->summary,
        ];

        if ($entry->details) {
            $lines[] = '';
            $lines[] = 'Details:';
            $lines[] = $entry->details;
        }

        return implode("\n", $lines);
    }

    protected function describeDateFilter(array $filter): string
    {
        if (isset($filter['date'])) {
            return $filter['date']->toDateString();
        }
        return $filter['from']->toDateString() . ' to ' . $filter['to']->toDateString();
    }
}

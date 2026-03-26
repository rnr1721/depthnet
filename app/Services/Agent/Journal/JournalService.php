<?php

namespace App\Services\Agent\Journal;

use App\Contracts\Agent\Capabilities\EmbeddingServiceInterface;
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
 *
 * Similarity engine:
 *   - If the preset has an embedding capability configured → cosine similarity
 *   - Otherwise → TF-IDF keyword similarity (automatic fallback)
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
        protected TfIdfServiceInterface    $tfIdfService,
        protected EmbeddingServiceInterface $embeddingService,
        protected JournalEntry             $journalModel,
        protected LoggerInterface          $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Add a new journal entry.
     *
     * TF-IDF vector is always computed.
     * Embedding is computed when the preset has an embedding capability configured.
     *
     * Format: "type | summary" or "type | summary | details"
     * Outcome can be appended: "type | summary | details | outcome:success"
     */
    public function addEntry(AiPreset $preset, string $content): array
    {
        try {
            $content = trim($content);
            if (empty($content)) {
                return ['success' => false, 'message' => 'Journal entry cannot be empty.'];
            }

            $parsed = $this->parseEntryContent($content);

            $text   = $parsed['summary'] . ' ' . ($parsed['details'] ?? '');
            $vector = $this->tfIdfService->vectorize($text);

            $entry = $this->journalModel->create([
                'preset_id'    => $preset->id,
                'recorded_at'  => now(),
                'type'         => $parsed['type'],
                'summary'      => $parsed['summary'],
                'details'      => $parsed['details'],
                'outcome'      => $parsed['outcome'],
                'tfidf_vector' => $vector,
            ]);

            // Attach embedding asynchronously after record is created.
            // Fire-and-forget: failure doesn't break journal write.
            $this->attachEmbedding($entry, $text, $preset);

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
     * Uses embedding cosine similarity when available, falls back to TF-IDF.
     */
    public function search(AiPreset $preset, string $query, int $limit = 10): array
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
                $dateStr = $dateFilter ? ' for the specified date' : '';
                return ['success' => true, 'message' => "No journal entries found{$dateStr}."];
            }

            if (!empty($semanticQuery)) {
                $matched = $this->semanticSearch($entries, $semanticQuery, $limit, $preset);

                if (empty($matched)) {
                    return ['success' => true, 'message' => "No entries matching \"{$semanticQuery}\" found."];
                }

                $header = "Journal search: \"{$semanticQuery}\"" . ($dateFilter ? ' (date filtered)' : '');
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
                return $this->semanticSearch($entries, $semanticQuery, $limit, $preset);
            }

            return $entries->take($limit)->all();

        } catch (\Throwable $e) {
            $this->logger->error('JournalService::searchEntries error: ' . $e->getMessage());
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Delete / Clear
    // -------------------------------------------------------------------------

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
    // Semantic search (embedding or TF-IDF)
    // -------------------------------------------------------------------------

    /**
     * Run semantic similarity search over a pre-filtered entry collection.
     *
     * Strategy:
     *  1. If preset has embedding capability → cosine similarity over dense vectors
     *     - Entries with embedding: cosine search
     *     - Entries without embedding: TF-IDF fallback (supplement)
     *  2. No embedding capability → pure TF-IDF
     *
     * @param  \Illuminate\Support\Collection  $entries
     * @return JournalEntry[]
     */
    private function semanticSearch(
        \Illuminate\Support\Collection $entries,
        string   $query,
        int      $limit,
        AiPreset $preset,
    ): array {
        // ── Try embedding search ──────────────────────────────────────────────
        if ($this->embeddingService->isAvailable($preset)) {
            $queryEmbedding = $this->embeddingService->embed($query, $preset);

            if ($queryEmbedding !== null) {
                $withEmbedding    = $entries->filter(fn ($e) => !empty($e->embedding));
                $withoutEmbedding = $entries->filter(fn ($e) => empty($e->embedding));

                $results = [];

                // Cosine similarity for entries that have embeddings
                foreach ($withEmbedding as $entry) {
                    $similarity = $this->embeddingService->cosineSimilarity(
                        $queryEmbedding,
                        $entry->embedding,
                    );
                    if ($similarity >= 0.2) {
                        $results[] = ['entry' => $entry, 'score' => $similarity];
                    }
                }

                usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);
                $results = array_slice($results, 0, $limit);

                // TF-IDF supplement for entries without embedding
                if ($withoutEmbedding->isNotEmpty() && count($results) < $limit) {
                    $seenIds   = array_column(array_column($results, 'entry'), 'id');
                    $remaining = $limit - count($results);

                    $tfidf = $this->tfIdfService->findSimilar(
                        $query,
                        $withoutEmbedding,
                        $remaining,
                        0.05,
                        false
                    );

                    foreach ($tfidf as $r) {
                        if (!in_array($r['document']->id, $seenIds, true)) {
                            $results[] = ['entry' => $r['document'], 'score' => $r['similarity']];
                        }
                    }
                }

                if (!empty($results)) {
                    return array_column($results, 'entry');
                }
            }
        }

        // ── TF-IDF fallback ───────────────────────────────────────────────────
        $tfidf = $this->tfIdfService->findSimilar(
            $query,
            $entries,
            $limit,
            0.05,
            false
        );

        return array_map(fn ($r) => $r['document'], $tfidf);
    }

    // -------------------------------------------------------------------------
    // Embedding helpers
    // -------------------------------------------------------------------------

    /**
     * Compute and attach embedding to a newly created journal entry.
     * Silent failure — TF-IDF remains the fallback.
     */
    private function attachEmbedding(JournalEntry $entry, string $text, AiPreset $preset): void
    {
        try {
            if (!$this->embeddingService->isAvailable($preset)) {
                return;
            }

            $vector = $this->embeddingService->embed($text, $preset);

            if ($vector === null) {
                return;
            }

            $entry->update([
                'embedding'     => $vector,
                'embedding_dim' => count($vector),
            ]);

        } catch (\Throwable $e) {
            $this->logger->warning('JournalService: failed to attach embedding.', [
                'entry_id' => $entry->id,
                'error'    => $e->getMessage(),
            ]);
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
            $summary = $parts[0];
        } elseif (in_array(strtolower($parts[0]), self::TYPES)) {
            $type    = strtolower(array_shift($parts));
            $summary = array_shift($parts) ?? '';
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
            $summary = array_shift($parts);
            if (!empty($parts)) {
                $details = implode("\n", $parts);
            }
        }

        return compact('type', 'summary', 'details', 'outcome');
    }

    /**
     * Parse search query into [dateFilter, semanticQuery].
     */
    protected function parseSearchQuery(string $query): array
    {
        $query = trim($query);

        if (str_contains($query, '|')) {
            [$datePart, $semanticPart] = array_map('trim', explode('|', $query, 2));
            $dateFilter = $this->parseDateExpression($datePart);
            if ($dateFilter !== null) {
                return [$dateFilter, $semanticPart];
            }
        }

        $dateFilter = $this->parseDateExpression($query);
        if ($dateFilter !== null) {
            return [$dateFilter, ''];
        }

        return [null, $query];
    }

    protected function parseDateExpression(string $expr): ?array
    {
        $expr = trim(strtolower($expr));

        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*:\s*(\d{4}-\d{2}-\d{2})$/', $expr, $m)) {
            return [
                'from' => Carbon::parse($m[1])->startOfDay(),
                'to'   => Carbon::parse($m[2])->endOfDay(),
            ];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expr)) {
            return ['date' => Carbon::parse($expr)];
        }

        return match ($expr) {
            'today'               => ['date' => Carbon::today()],
            'yesterday'           => ['date' => Carbon::yesterday()],
            'last week', 'week'   => ['from' => Carbon::now()->subDays(7)->startOfDay(), 'to' => Carbon::now()->endOfDay()],
            'this week'           => ['from' => Carbon::now()->startOfWeek()->startOfDay(), 'to' => Carbon::now()->endOfDay()],
            'last month', 'month' => ['from' => Carbon::now()->subDays(30)->startOfDay(), 'to' => Carbon::now()->endOfDay()],
            default               => null,
        };
    }

    // -------------------------------------------------------------------------
    // Formatting helpers
    // -------------------------------------------------------------------------

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

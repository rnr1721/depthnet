<?php

namespace App\Services\Agent\VectorMemory;

use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use App\Contracts\Agent\PulseServiceInterface;
use App\Contracts\Agent\Search\SearchDateParserInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryServiceInterface;
use App\Models\AiPreset;
use App\Models\VectorMemory;
use App\Services\Agent\VectorMemory\VectorMemoryQuery;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Service for managing vector memory operations
 * Handles CRUD operations, semantic search, and memory limit enforcement
 *
 * Domain support:
 *   Each record belongs to exactly one named domain (default: 'global').
 *   Domains are agent-managed namespaces — no separate domain table:
 *   a domain exists as long as at least one record uses its name.
 *
 *   - storeVectorMemory($preset, $content, ['domain' => 'work']) writes to 'work'
 *   - searchVectorMemories accepts $config['domains'] = ['work', 'global'] for filtering
 *   - searchVectorMemories also parses inline "domain:work,global | actual query"
 *   - getVectorMemories accepts a VectorMemoryQuery with domains/from/to/limit
 *   - Empty/missing domains list = search across ALL domains
 *   - $config['domains'] (when set) wins over inline syntax
 *
 * Temporal filtering:
 *   - $config['from'] / $config['to'] — Carbon, RAG-config style
 *   - Inline "time:<expr> | rest" — primary inline form
 *   - Bare keyword as first prefix ("yesterday | rest") — fallback
 *
 *   When query is empty after parsing but a time filter is set, search
 *   switches to temporal mode: chronological listing in the window, newest
 *   first, no semantic ranking.
 */
class VectorMemoryService implements VectorMemoryServiceInterface
{
    /**
     * Characters not allowed in a domain name — they would break the
     * inline parser ("domain:a,b | query") and command tag content.
     */
    protected const DOMAIN_FORBIDDEN_CHARS = ['|', ',', ':', '"', "'", "\n", "\r", "\t"];

    /**
     * Max domain name length (matches DB column).
     */
    protected const DOMAIN_MAX_LENGTH = 64;

    public function __construct(
        protected LoggerInterface           $logger,
        protected TfIdfServiceInterface     $tfIdfService,
        protected VectorMemoryImporter      $importer,
        protected VectorMemoryExporter      $exporter,
        protected VectorMemory              $vectorMemoryModel,
        protected SearchDateParserInterface $searchDateParser,
        protected PulseServiceInterface     $pulseService,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getPaginatedVectorMemories(AiPreset $preset, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = max(10, min(100, $perPage));

        return $this->vectorMemoryModel
            ->where('preset_id', $preset->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @inheritDoc
     */
    public function getVectorMemories(AiPreset $preset, VectorMemoryQuery $query = new VectorMemoryQuery()): Collection
    {
        $dbQuery = $this->vectorMemoryModel
            ->where('preset_id', $preset->id)
            ->orderBy('created_at', 'desc');

        if ($query->hasDomainFilter()) {
            $dbQuery->whereIn('domain', $query->domains);
        }

        // Time filter is applied on created_at — vector memories are
        // immutable as far as "when did I store this" goes, so created_at
        // is the right column. last_accessed_at exists for associative
        // scoring, not for "what did I think on Monday" queries.
        if ($query->from !== null) {
            $dbQuery->where('created_at', '>=', $query->from);
        }
        if ($query->to !== null) {
            $dbQuery->where('created_at', '<=', $query->to);
        }

        if ($query->limit !== null) {
            $dbQuery->limit($query->limit);
        }

        $memories = $dbQuery->get();

        // Pulse filter is applied AFTER the DB fetch because pulse position
        // is derived from created_at (not a stored column). For typical preset
        // sizes (≤1000 records) this is fine; denormalising into a
        // pulse_position column with an index is the obvious next step if
        // this ever becomes a hot path.
        if ($query->hasPulseFilter()) {
            $memories = $memories->filter(
                fn (VectorMemory $m) => $this->matchesPulseRange(
                    $m->created_at,
                    $query->pulseFrom,
                    $query->pulseTo,
                )
            )->values();
        }

        return $memories;
    }

    /**
     * Whether a moment's pulse position falls within the given range.
     *
     * Range semantics:
     *   - Both bounds set, from ≤ to → linear range [from..to]
     *   - Both bounds set, from > to → CIRCULAR range [from..999] ∪ [0..to]
     *     (the "across midnight" case — e.g. 800..200 for night-owl hours)
     *   - Only from set                → [from..999]
     *   - Only to set                  → [0..to]
     *   - Neither (caller bug)         → true (no filter)
     */
    protected function matchesPulseRange(
        ?CarbonInterface $moment,
        ?int $pulseFrom,
        ?int $pulseTo,
    ): bool {
        if ($moment === null) {
            // Defensive: if a record somehow has no created_at, don't drop it
            // on pulse grounds — return true. The caller is responsible for
            // deciding whether such records should be filtered out elsewhere.
            return true;
        }

        if ($pulseFrom === null && $pulseTo === null) {
            return true;
        }

        $pulse = $this->pulseService->currentPulse(
            $moment instanceof \Carbon\Carbon
                ? $moment
                : \Carbon\Carbon::instance($moment)
        );

        if ($pulseFrom !== null && $pulseTo === null) {
            return $pulse >= $pulseFrom;
        }

        if ($pulseFrom === null && $pulseTo !== null) {
            return $pulse <= $pulseTo;
        }

        // Both set
        if ($pulseFrom <= $pulseTo) {
            return $pulse >= $pulseFrom && $pulse <= $pulseTo;
        }

        // Circular: from > to means "wraps midnight"
        return $pulse >= $pulseFrom || $pulse <= $pulseTo;
    }


    /**
     * @inheritDoc
     */
    public function storeVectorMemory(AiPreset $preset, string $content, array $config = []): array
    {
        try {
            $content = trim($content);
            if (empty($content)) {
                return [
                    'success' => false,
                    'message' => 'Error: Cannot store empty content.'
                ];
            }

            $domain = $this->resolveDomain($config['domain'] ?? null, $config);
            if ($domain === null) {
                return [
                    'success' => false,
                    'message' => 'Error: Invalid domain name. Forbidden chars: | , : " \' or length > '
                        . self::DOMAIN_MAX_LENGTH . '.'
                ];
            }

            $this->cleanupIfNeeded($preset, $config);
            $this->configureTfIdfService($config);

            $language = $this->determineLanguage($content, $config);
            $vector   = $this->tfIdfService->vectorize($content);
            $keywords = $this->extractKeywords($content, $language);

            $vectorMemory = $this->vectorMemoryModel->create([
                'preset_id'    => $preset->id,
                'domain'       => $domain,
                'content'      => $content,
                'tfidf_vector' => $vector,
                'keywords'     => $keywords,
                'importance'   => 1.0,
            ]);

            return [
                'success'        => true,
                'message'        => "Content stored in vector memory [{$domain}]. Generated " . count($vector) . " features (language: {$language}).",
                'memory'         => $vectorMemory,
                'domain'         => $domain,
                'language'       => $language,
                'features_count' => count($vector)
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::storeVectorMemory error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error storing content: " . $e->getMessage()
            ];
        }
    }

    /**
     * Store memory with preserved metadata (used during import).
     *
     * Restores original created_at, updated_at, access_count, last_accessed_at
     * from export data. Gracefully handles v1/v2 exports where these fields
     * (including 'domain') are absent — missing domain falls back to default.
     *
     * @param AiPreset $preset
     * @param string $content
     * @param array $meta Metadata from export: importance, access_count, last_accessed_at, created_at, updated_at, domain
     * @param array $config
     * @return array
     */
    public function storeWithMeta(AiPreset $preset, string $content, array $meta, array $config = []): array
    {
        try {
            $content = trim($content);
            if (empty($content)) {
                return [
                    'success' => false,
                    'message' => 'Error: Cannot store empty content.'
                ];
            }

            // Admin-side import override: $config['force_domain'] beats $meta['domain'].
            // This lets the user funnel an entire export into one chosen domain regardless
            // of the source records' own domain metadata.
            $rawDomain = $config['force_domain'] ?? $meta['domain'] ?? null;
            $domain    = $this->resolveDomain($rawDomain, $config);
            if ($domain === null) {
                // Bad domain in import — fall back to default, don't fail the import
                $domain = $config['default_domain'] ?? VectorMemory::DEFAULT_DOMAIN;
                $this->logger->warning('VectorMemoryService::storeWithMeta: invalid domain in import, using default.', [
                    'preset_id'         => $preset->id,
                    'raw_domain'        => $meta['domain'] ?? null,
                    'fallback_domain'   => $domain,
                ]);
            }

            $this->cleanupIfNeeded($preset, $config);
            $this->configureTfIdfService($config);

            $language = $this->determineLanguage($content, $config);
            $vector   = $this->tfIdfService->vectorize($content);
            $keywords = $this->extractKeywords($content, $language);

            $now = now();

            $data = [
                'preset_id'        => $preset->id,
                'domain'           => $domain,
                'content'          => $content,
                'tfidf_vector'     => $vector,
                'keywords'         => $keywords,
                'importance'       => $meta['importance'] ?? 1.0,
                'access_count'     => $meta['access_count'] ?? 0,
                'last_accessed_at' => isset($meta['last_accessed_at'])
                    ? $this->parseDateTime($meta['last_accessed_at'])
                    : null,
            ];

            // Determine timestamps to restore
            $createdAt = isset($meta['created_at'])
                ? $this->parseDateTime($meta['created_at'])
                : $now;

            $updatedAt = isset($meta['updated_at'])
                ? $this->parseDateTime($meta['updated_at'])
                : $createdAt;

            // Create record and then restore original timestamps without triggering auto-update
            $vectorMemory = null;

            $this->vectorMemoryModel->withoutTimestamps(function () use (&$vectorMemory, $data, $createdAt, $updatedAt) {
                $vectorMemory             = $this->vectorMemoryModel->create($data);
                $vectorMemory->created_at = $createdAt;
                $vectorMemory->updated_at = $updatedAt;
                $vectorMemory->saveQuietly();
            });

            return [
                'success'        => true,
                'message'        => "Content imported into [{$domain}]. Generated " . count($vector) . " features (language: {$language}).",
                'memory'         => $vectorMemory,
                'domain'         => $domain,
                'language'       => $language,
                'features_count' => count($vector)
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::storeWithMeta error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error importing content: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function searchVectorMemories(AiPreset $preset, string $query, array $config = []): array
    {
        try {
            $query = trim($query);
            if (empty($query)) {
                return [
                    'success' => false,
                    'message' => 'Error: Search query cannot be empty.'
                ];
            }

            // Both inline prefixes ("domain:" and "time:") can appear in any order.
            // Peel them iteratively until no recognised prefix remains, so the
            // user can write either ordering without surprises.
            $domains = [];
            $from = $to = null;
            $cleanQuery = $query;

            [$domains, $from, $to, $pulseFrom, $pulseTo, $cleanQuery] = $this->peelSearchPrefixes($query, $config);

            $hasTimeFilter = ($from !== null || $to !== null);

            if (empty($cleanQuery) && !$hasTimeFilter) {
                return [
                    'success' => false,
                    'message' => 'Error: Search query cannot be empty after parsing prefixes.'
                ];
            }

            $memQuery = new VectorMemoryQuery(
                domains:   $domains,
                from:      $from,
                to:        $to,
                pulseFrom: $pulseFrom,
                pulseTo:   $pulseTo,
            );

            $memories = $this->getVectorMemories($preset, $memQuery);

            if ($memories->isEmpty()) {
                return [
                    'success'  => true,
                    'message'  => $this->describeEmptyResult($domains, $from, $to),
                    'results'  => [],
                    'domains'  => $domains,
                    'from'     => $from,
                    'to'       => $to,
                    'temporal' => empty($cleanQuery),
                ];
            }

            // Temporal mode: no semantic query, just chronological listing.
            // Already ordered by created_at desc in getVectorMemories().
            if (empty($cleanQuery)) {
                $limit  = $config['search_limit'] ?? 5;
                $sliced = $memories->take($limit);

                $results = $sliced->map(fn (VectorMemory $m) => [
                    'document'   => $m,
                    'memory'     => $m,
                    'similarity' => 1.0, // No semantic ranking — surfaced by time only
                    'source'     => 'temporal',
                ])->all();

                return [
                    'success'        => true,
                    'message'        => 'Found ' . count($results) . ' memories in time window.',
                    'results'        => $results,
                    'total_searched' => $memories->count(),
                    'domains'        => $domains,
                    'from'           => $from,
                    'to'             => $to,
                    'temporal'       => true,
                ];
            }

            $results = $this->tfIdfService->findSimilar(
                $cleanQuery,
                $memories,
                $config['search_limit'] ?? 5,
                $config['similarity_threshold'] ?? 0.1,
                $config['boost_recent'] ?? true
            );

            return [
                'success'        => true,
                'message'        => "Found " . count($results) . " similar memories.",
                'results'        => $results,
                'total_searched' => $memories->count(),
                'domains'        => $domains,
                'from'           => $from,
                'to'             => $to,
                'temporal'       => false,
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::searchVectorMemories error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error searching memories: " . $e->getMessage()
            ];
        }
    }

    /**
     * Build a friendly "nothing found" message based on which filters were active.
     */
    protected function describeEmptyResult(
        array $domains,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        ?int $pulseFrom = null,
        ?int $pulseTo = null,
    ): string {
        $parts = [];
        if (!empty($domains)) {
            $parts[] = 'in [' . implode(', ', $domains) . ']';
        }
        if ($from !== null && $to !== null) {
            $parts[] = $from->isSameDay($to)
                ? 'on ' . $from->toDateString()
                : 'between ' . $from->toDateString() . ' and ' . $to->toDateString();
        } elseif ($from !== null) {
            $parts[] = 'from ' . $from->toDateString();
        } elseif ($to !== null) {
            $parts[] = 'until ' . $to->toDateString();
        }
        if ($pulseFrom !== null || $pulseTo !== null) {
            $parts[] = $this->describePulseRange($pulseFrom, $pulseTo);
        }

        return empty($parts)
            ? 'No memories found.'
            : 'No memories found ' . implode(' ', $parts) . '.';
    }

    /**
     * Compose a short pulse-range description for empty-result messages.
     */
    private function describePulseRange(?int $pulseFrom, ?int $pulseTo): string
    {
        if ($pulseFrom !== null && $pulseTo !== null) {
            $note = ($pulseFrom > $pulseTo) ? ' (across midnight)' : '';
            return "in pulse range {$pulseFrom}-{$pulseTo}{$note}";
        }
        if ($pulseFrom !== null) {
            return "from pulse {$pulseFrom}";
        }
        return "up to pulse {$pulseTo}";
    }

    /**
     * @inheritDoc
     */
    public function listDomains(AiPreset $preset): array
    {
        return $this->vectorMemoryModel
            ->where('preset_id', $preset->id)
            ->selectRaw('domain, COUNT(*) as cnt')
            ->groupBy('domain')
            ->orderByDesc('cnt')
            ->get()
            ->map(fn ($row) => [
                'name'  => $row->domain,
                'count' => (int) $row->cnt,
            ])
            ->all();
    }

    /**
     * @inheritDoc
     */
    public function dropDomain(AiPreset $preset, string $domain, array $config = []): int
    {
        $defaultDomain = $config['default_domain'] ?? VectorMemory::DEFAULT_DOMAIN;

        if ($domain === $defaultDomain) {
            return 0;
        }

        return $this->vectorMemoryModel
            ->where('preset_id', $preset->id)
            ->where('domain', $domain)
            ->update(['domain' => $defaultDomain]);
    }

    /**
     * @inheritDoc
     */
    public function purgeDomain(AiPreset $preset, string $domain): int
    {
        return $this->vectorMemoryModel
            ->where('preset_id', $preset->id)
            ->where('domain', $domain)
            ->delete();
    }

    /**
     * @inheritDoc
     */
    public function getRecentVectorMemories(AiPreset $preset, int $limit = 5): array
    {
        try {
            $limit    = max(1, min($limit, 20));
            $memories = $this->getVectorMemories($preset, $limit);

            return [
                'success'  => true,
                'message'  => "Retrieved {$memories->count()} recent memories.",
                'memories' => $memories
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::getRecentVectorMemories error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error retrieving recent memories: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteVectorMemory(AiPreset $preset, int $memoryId): array
    {
        try {
            $memory = $this->vectorMemoryModel->where('preset_id', $preset->id)
                ->where('id', $memoryId)
                ->first();

            if (!$memory) {
                return [
                    'success' => false,
                    'message' => 'Memory not found.'
                ];
            }

            $memory->delete();

            return [
                'success' => true,
                'message' => 'Vector memory deleted successfully.'
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::deleteVectorMemory error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error deleting memory: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function clearVectorMemories(AiPreset $preset): array
    {
        try {
            $count = $this->vectorMemoryModel->where('preset_id', $preset->id)->count();
            $this->vectorMemoryModel->where('preset_id', $preset->id)->delete();

            return [
                'success'       => true,
                'message'       => "Cleared {$count} vector memories successfully.",
                'deleted_count' => $count
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::clearVectorMemories error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error clearing memories: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getVectorMemoryStats(AiPreset $preset, array $config = []): array
    {
        try {
            $maxEntries = $config['max_entries'] ?? 1000;

            $totalCount = $this->vectorMemoryModel
                ->where('preset_id', $preset->id)
                ->count();

            $timestamps = $this->vectorMemoryModel
                ->where('preset_id', $preset->id)
                ->selectRaw('MIN(created_at) as oldest, MAX(created_at) as newest')
                ->first();

            // Vocabulary and avg vector size still need records, but only the vector column
            $avgVectorSize  = 0;
            $vocabularySize = 0;

            if ($totalCount > 0) {
                $vectors = $this->vectorMemoryModel
                    ->where('preset_id', $preset->id)
                    ->pluck('tfidf_vector');

                $totalFeatures = 0;
                $allWords      = [];

                foreach ($vectors as $vector) {
                    $arr            = is_array($vector) ? $vector : [];
                    $totalFeatures += count($arr);
                    array_push($allWords, ...array_keys($arr));
                }

                $avgVectorSize  = $totalFeatures / $totalCount;
                $vocabularySize = count(array_unique($allWords));
            }

            return [
                'total_memories'      => $totalCount,
                'max_entries'         => $maxEntries,
                'usage_percentage'    => $maxEntries > 0 ? round(($totalCount / $maxEntries) * 100, 2) : 0,
                'average_vector_size' => round($avgVectorSize, 1),
                'vocabulary_size'     => $vocabularySize,
                'is_near_limit'       => $totalCount > ($maxEntries * 0.8),
                'is_over_limit'       => $totalCount > $maxEntries,
                'oldest_memory'       => $timestamps->oldest ?? null,
                'newest_memory'       => $timestamps->newest ?? null,
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::getVectorMemoryStats error: " . $e->getMessage());
            return [
                'total_memories' => 0,
                'error'          => $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function updateVectorMemoryImportance(AiPreset $preset, int $memoryId, float $importance): array
    {
        try {
            $memory = $this->vectorMemoryModel->where('preset_id', $preset->id)
                ->where('id', $memoryId)
                ->first();

            if (!$memory) {
                return [
                    'success' => false,
                    'message' => 'Memory not found.'
                ];
            }

            $importance = max(0.1, min(5.0, $importance));
            $memory->update(['importance' => $importance]);

            return [
                'success' => true,
                'message' => 'Memory importance updated successfully.',
                'memory'  => $memory
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::updateVectorMemoryImportance error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error updating memory importance: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getVectorMemoryById(AiPreset $preset, int $memoryId): ?VectorMemory
    {
        return $this->vectorMemoryModel->where('preset_id', $preset->id)
            ->where('id', $memoryId)
            ->first();
    }

    /**
     * @inheritDoc
     */
    public function searchByKeywords(AiPreset $preset, array $keywords): Collection
    {
        $query = $this->vectorMemoryModel->where('preset_id', $preset->id);

        foreach ($keywords as $keyword) {
            $query->whereJsonContains('keywords', $keyword);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * @inheritDoc
     */
    public function exportVectorMemories(AiPreset $preset): array
    {
        try {
            $memories = $this->getVectorMemories($preset);

            if ($memories->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'No vector memories to export.'
                ];
            }

            return $this->exporter->export($preset, $memories);

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::exportVectorMemories error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Export failed: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function importVectorMemories(
        AiPreset $preset,
        string $content,
        bool $isJson,
        bool $replaceExisting,
        array $config
    ): array {
        if ($replaceExisting) {
            $clearResult = $this->clearVectorMemories($preset);
            if (!$clearResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to clear existing memories: ' . $clearResult['message']
                ];
            }
        }

        return $this->importer->importFromContent(
            $preset,
            $content,
            $isJson,
            false,
            $config,
            // Meta is now passed as third argument from importer
            fn (AiPreset $preset, string $content, array $meta, array $config) =>
                $this->storeWithMeta($preset, $content, $meta, $config)
        );
    }

    /**
     * @inheritDoc
     */
    public function testConnection(AiPreset $preset): array
    {
        try {
            $testContent = 'Vector memory test - ' . time();
            $vector      = $this->tfIdfService->vectorize($testContent);

            if (!is_array($vector) || empty($vector)) {
                return [
                    'success' => false,
                    'message' => 'TF-IDF service is not working properly.'
                ];
            }

            $storeResult = $this->storeVectorMemory($preset, $testContent);
            if (!$storeResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Database storage test failed: ' . $storeResult['message']
                ];
            }

            $searchResult = $this->searchVectorMemories($preset, 'test');
            if (!$searchResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Search test failed: ' . $searchResult['message']
                ];
            }

            if (isset($storeResult['memory'])) {
                $this->deleteVectorMemory($preset, $storeResult['memory']->id);
            }

            return [
                'success'           => true,
                'message'           => 'Vector memory service is working correctly.',
                'features_generated' => count($vector)
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::testConnection error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Connection test failed: " . $e->getMessage()
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Domain helpers
    // -------------------------------------------------------------------------

    /**
     * Normalize and validate a domain name. Returns null on invalid input.
     *
     * Rules:
     *  - trim + mb_strtolower
     *  - empty → fallback to config's default_domain or VectorMemory::DEFAULT_DOMAIN
     *  - no forbidden chars (would break parser)
     *  - length ≤ DOMAIN_MAX_LENGTH
     *
     * NOTE: We do NOT restrict alphabet (no ASCII-only). VectorMemoryPlugin's
     * language_mode already steers the agent toward a consistent naming language.
     */
    protected function resolveDomain(?string $raw, array $config): ?string
    {
        $defaultDomain = $config['default_domain'] ?? VectorMemory::DEFAULT_DOMAIN;

        if ($raw === null || trim($raw) === '') {
            return $defaultDomain;
        }

        $normalized = mb_strtolower(trim($raw));

        foreach (self::DOMAIN_FORBIDDEN_CHARS as $forbidden) {
            if (str_contains($normalized, $forbidden)) {
                return null;
            }
        }

        if (mb_strlen($normalized) > self::DOMAIN_MAX_LENGTH) {
            return null;
        }

        return $normalized;
    }

    /**
     * Resolve domain filter for a search call.
     * Returns [array $domains, string $cleanQuery].
     *
     * Priority:
     *   1. $config['domains'] (RAG-config style)
     *   2. Inline "domain:a,b | actual query"
     *   3. No filter — search all domains
     */
    protected function resolveSearchDomains(string $query, array $config): array
    {
        // Config takes precedence
        if (!empty($config['domains']) && is_array($config['domains'])) {
            $valid = [];
            foreach ($config['domains'] as $raw) {
                $resolved = $this->resolveDomain((string) $raw, $config);
                if ($resolved !== null) {
                    $valid[] = $resolved;
                }
            }
            return [array_values(array_unique($valid)), $query];
        }

        // Inline prefix: "domain:a,b | rest" — `rest` may be empty (so this
        // composes cleanly with subsequent time-only prefixes during peel)
        if (preg_match('/^domain\s*:\s*([^|]+)\|(.*)$/iu', $query, $m)) {
            $rawDomains = array_filter(array_map('trim', explode(',', $m[1])));
            $valid = [];
            foreach ($rawDomains as $raw) {
                $resolved = $this->resolveDomain($raw, $config);
                if ($resolved !== null) {
                    $valid[] = $resolved;
                }
            }
            return [array_values(array_unique($valid)), trim($m[2])];
        }

        return [[], $query];
    }

    /**
     * Resolve time filter for a search call.
     * Returns [?CarbonInterface $from, ?CarbonInterface $to, string $cleanQuery].
     *
     * Priority:
     *   1. $config['from'] / $config['to'] — Carbon, RAG-config style.
     *      Either or both may be set; the other defaults to null.
     *   2. Inline "time:<expr> | rest" — primary inline form. <expr> can be
     *      anything SearchDateParser understands: "yesterday", "2026-03",
     *      "2025", "2026-03-10:2026-03-15", "last week", etc.
     *   3. Bare date keyword as the first prefix — fallback for natural
     *      writing like "yesterday | meeting notes". Only triggers when the
     *      first pipe-separated chunk is unambiguously a date expression.
     *   4. No filter.
     *
     * Invariant: when this method returns from/to, they are absolute Carbon
     * instances. No keyword resolution happens downstream.
     */
    protected function resolveSearchTime(string $query, array $config): array
    {
        // Config takes precedence — RAG configs and admin overrides.
        // Accept either Carbon flavour (CarbonInterface covers both
        // Carbon\Carbon and Illuminate\Support\Carbon).
        $configFrom = $config['from'] ?? null;
        $configTo   = $config['to']   ?? null;
        if ($configFrom instanceof CarbonInterface || $configTo instanceof CarbonInterface) {
            return [
                $configFrom instanceof CarbonInterface ? $configFrom : null,
                $configTo   instanceof CarbonInterface ? $configTo : null,
                $query,
            ];
        }

        // Inline "time:<expr> | rest" — `rest` may be empty (pure temporal query)
        if (preg_match('/^time\s*:\s*([^|]+)\|(.*)$/iu', $query, $m)) {
            $expr = trim($m[1]);
            $range = $this->searchDateParser->parseDateExpression($expr);
            if ($range !== null) {
                [$from, $to] = $range;
                return [$from, $to, trim($m[2])];
            }
            // time: was named but the expression isn't recognised — treat
            // the whole thing as semantic. Don't silently drop the prefix.
            return [null, null, $query];
        }

        // "time:<expr>" without pipe — strip prefix and parse
        if (preg_match('/^time\s*:\s*(.+)$/iu', $query, $m)) {
            $expr = trim($m[1]);
            $range = $this->searchDateParser->parseDateExpression($expr);
            if ($range !== null) {
                [$from, $to] = $range;
                return [$from, $to, ''];  // empty semantic part → temporal mode
            }
            // Not a recognised date expression — leave intact for semantic search
            return [null, null, $query];
        }

        // Fallback: bare date keyword as first chunk.
        // Only the FIRST "|" matters here; if there is no "|", the parser
        // will also accept a whole-query date (e.g. "yesterday" alone).
        if (str_contains($query, '|')) {
            [$head, $tail] = array_map('trim', explode('|', $query, 2));
            $range = $this->searchDateParser->parseDateExpression($head);
            if ($range !== null) {
                [$from, $to] = $range;
                return [$from, $to, $tail];
            }
        } else {
            // No pipe — could the whole query be just a date keyword?
            $range = $this->searchDateParser->parseDateExpression($query);
            if ($range !== null) {
                [$from, $to] = $range;
                return [$from, $to, ''];
            }
        }

        return [null, null, $query];
    }

    /**
     * Iteratively strip domain:, time:, and pulse: prefixes from the query,
     * in whatever order they appear inline. Config-level filters (RAG-style)
     * are applied via the resolvers in their initial call.
     *
     * Returns [array $domains, ?CarbonInterface $from, ?CarbonInterface $to,
     *          ?int $pulseFrom, ?int $pulseTo, string $cleanQuery].
     *
     * Algorithm:
     *   1. Call each resolver once WITH config — this captures any
     *      RAG-pinned filters AND, if the first inline prefix matches,
     *      strips it too.
     *   2. Loop calling each resolver WITHOUT config (empty array) until
     *      none strips anything more. This handles the case where the
     *      user wrote prefixes in any order.
     *
     * The loop is capped at a few iterations as a safety net.
     */
    protected function peelSearchPrefixes(string $query, array $config): array
    {
        // First pass — with config. Captures RAG-pinned filters and strips
        // whichever inline prefix appears first.
        [$domains, $cleanQuery]               = $this->resolveSearchDomains($query, $config);
        [$from, $to, $cleanQuery]             = $this->resolveSearchTime($cleanQuery, $config);
        [$pulseFrom, $pulseTo, $cleanQuery]   = $this->resolveSearchPulse($cleanQuery, $config);

        // Loop with empty config to peel any remaining inline prefix.
        $emptyConfig = [];
        for ($i = 0; $i < 5; $i++) {
            $progressed = false;

            if (empty($domains)) {
                [$d, $afterD] = $this->resolveSearchDomains($cleanQuery, $emptyConfig);
                if (!empty($d)) {
                    $domains    = $d;
                    $cleanQuery = $afterD;
                    $progressed = true;
                }
            }

            if ($from === null && $to === null) {
                [$f, $t, $afterT] = $this->resolveSearchTime($cleanQuery, $emptyConfig);
                if ($f !== null || $t !== null) {
                    $from       = $f;
                    $to         = $t;
                    $cleanQuery = $afterT;
                    $progressed = true;
                }
            }

            if ($pulseFrom === null && $pulseTo === null) {
                [$pf, $pt, $afterP] = $this->resolveSearchPulse($cleanQuery, $emptyConfig);
                if ($pf !== null || $pt !== null) {
                    $pulseFrom  = $pf;
                    $pulseTo    = $pt;
                    $cleanQuery = $afterP;
                    $progressed = true;
                }
            }

            if (!$progressed) {
                break;
            }
        }

        return [$domains, $from, $to, $pulseFrom, $pulseTo, trim($cleanQuery)];
    }


    // -------------------------------------------------------------------------
    // Existing helpers — unchanged
    // -------------------------------------------------------------------------

    /**
     * Configure TF-IDF service with custom language settings
     */
    protected function configureTfIdfService(array $config): void
    {
        $languageConfig = [];

        if (!empty($config['custom_stop_words_ru'])) {
            $customRu = array_map('trim', explode(',', $config['custom_stop_words_ru']));
            $languageConfig['ru']['stop_words'] = array_merge(
                $languageConfig['ru']['stop_words'] ?? [],
                $customRu
            );
        }

        if (!empty($config['custom_stop_words_en'])) {
            $customEn = array_map('trim', explode(',', $config['custom_stop_words_en']));
            $languageConfig['en']['stop_words'] = array_merge(
                $languageConfig['en']['stop_words'] ?? [],
                $customEn
            );
        }

        if (!empty($languageConfig)) {
            $this->tfIdfService->setLanguageConfig(['languages' => $languageConfig]);
        }
    }

    /**
     * Determine language for content based on config
     */
    protected function determineLanguage(string $content, array $config): string
    {
        $mode = $config['language_mode'] ?? 'auto';

        return match ($mode) {
            'ru'            => 'ru',
            'en'            => 'en',
            'de'            => 'de',
            'fr'            => 'fr',
            'es'            => 'es',
            'auto'          => $this->tfIdfService->detectLanguage($content),
            'multilingual'  => 'auto',
            default         => 'auto'
        };
    }

    /**
     * Extract keywords from content
     */
    protected function extractKeywords(string $content, string $language = 'auto'): array
    {
        $words = $this->tfIdfService->tokenize($content, $language);

        $keywords = array_filter($words, function ($word) {
            return strlen($word) > 2;
        });

        return array_values(array_unique($keywords));
    }

    /**
     * Cleanup entries if limit is reached.
     *
     * Deletes the weakest memories first using a lightweight composite score:
     *   importance * (1 + log(1 + access_count))
     * Only scalar columns are fetched — no vectors loaded into memory.
     *
     * NOTE: This is a per-preset limit, NOT per-domain. A noisy domain can
     * push records out of quieter ones. Per-domain quotas are intentionally
     * deferred until there's a clear use case.
     */
    protected function cleanupIfNeeded(AiPreset $preset, array $config): void
    {
        if (!($config['auto_cleanup'] ?? true)) {
            return;
        }

        $maxEntries   = $config['max_entries'] ?? 1000;
        $currentCount = $this->vectorMemoryModel->where('preset_id', $preset->id)->count();

        if ($currentCount < $maxEntries) {
            return;
        }

        $deleteCount = $currentCount - $maxEntries + 1;

        $memories = $this->vectorMemoryModel
            ->where('preset_id', $preset->id)
            ->get(['id', 'importance', 'access_count']);

        $scored = $memories->map(fn ($m) => [
            'id'    => $m->id,
            'score' => ($m->importance ?? 1.0) * (1 + log(1 + ($m->access_count ?? 0))),
        ])->sortBy('score');

        $idsToDelete = $scored->take($deleteCount)->pluck('id')->toArray();

        $this->vectorMemoryModel->whereIn('id', $idsToDelete)->delete();

        $this->logger->info("VectorMemoryService: cleaned up {$deleteCount} weakest memories for preset {$preset->id}.");
    }

    /**
     * Parse a datetime string into a Carbon instance, returning null on failure.
     */
    protected function parseDateTime(?string $value): ?CarbonInterface
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve pulse filter for a search call.
     * Returns [?int $pulseFrom, ?int $pulseTo, string $cleanQuery].
     *
     * Syntax:
     *   - "pulse:N-M | rest"  → linear or circular range, depending on N vs M
     *   - "pulse:-M | rest"   → upper bound only (early-day filter)
     *   - "pulse:N- | rest"   → lower bound only (late-day filter)
     *   - "pulse:N | rest"    → single-point queries are rejected (too narrow
     *                            to be useful — one pulse ≈ 86 seconds)
     *
     * Bounds are clamped to [0..999]. Invalid syntax silently leaves the
     * pulse: prefix in the query for downstream semantic matching, mirroring
     * the resolveSearchTime() failure mode.
     *
     * Priority:
     *   1. $config['pulse_from'] / $config['pulse_to'] — RAG-config style
     *   2. Inline "pulse:..." prefix
     *   3. No filter
     */
    protected function resolveSearchPulse(string $query, array $config): array
    {
        // Config takes precedence
        $configFrom = $config['pulse_from'] ?? null;
        $configTo   = $config['pulse_to']   ?? null;
        if ($configFrom !== null || $configTo !== null) {
            return [
                is_numeric($configFrom) ? $this->clampPulse((int) $configFrom) : null,
                is_numeric($configTo) ? $this->clampPulse((int) $configTo) : null,
                $query,
            ];
        }

        // Inline "pulse:<expr> | rest" — `rest` may be empty
        if (preg_match('/^pulse\s*:\s*([^|]+)\|(.*)$/iu', $query, $m)) {
            $expr = trim($m[1]);
            $parsed = $this->parsePulseExpression($expr);
            if ($parsed !== null) {
                return [$parsed[0], $parsed[1], trim($m[2])];
            }
            return [null, null, $query];
        }

        // "pulse:<expr>" without pipe — useful for pure pulse-only filtering
        if (preg_match('/^pulse\s*:\s*(.+)$/iu', $query, $m)) {
            $expr = trim($m[1]);
            $parsed = $this->parsePulseExpression($expr);
            if ($parsed !== null) {
                return [$parsed[0], $parsed[1], ''];
            }
            return [null, null, $query];
        }

        return [null, null, $query];
    }

    /**
     * Parse a pulse range expression: "N-M", "-M", "N-".
     * Single numbers ("N") are intentionally rejected — one pulse is ~86s wide,
     * a point query against it almost never matches anything useful.
     *
     * Returns [from, to] or null on parse failure.
     *
     * @return array{0: ?int, 1: ?int}|null
     */
    private function parsePulseExpression(string $expr): ?array
    {
        // "N-M" — both bounds
        if (preg_match('/^(\d{1,3})\s*-\s*(\d{1,3})$/', $expr, $m)) {
            return [
                $this->clampPulse((int) $m[1]),
                $this->clampPulse((int) $m[2]),
            ];
        }

        // "-M" — upper bound only
        if (preg_match('/^-\s*(\d{1,3})$/', $expr, $m)) {
            return [null, $this->clampPulse((int) $m[1])];
        }

        // "N-" — lower bound only
        if (preg_match('/^(\d{1,3})\s*-$/', $expr, $m)) {
            return [$this->clampPulse((int) $m[1]), null];
        }

        // Lone number, no dash → reject
        return null;
    }

    /**
     * Clamp a pulse value into the canonical [0..999] range.
     */
    private function clampPulse(int $value): int
    {
        return max(0, min(999, $value));
    }



}

<?php

namespace App\Services\Agent\VectorMemory;

use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryServiceInterface;
use App\Models\AiPreset;
use App\Models\VectorMemory;
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
 *   - getVectorMemories accepts optional $domains list
 *   - Empty/missing domains list = search across ALL domains
 *   - $config['domains'] (when set) wins over inline syntax
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
        protected LoggerInterface $logger,
        protected TfIdfServiceInterface $tfIdfService,
        protected VectorMemoryImporter $importer,
        protected VectorMemoryExporter $exporter,
        protected VectorMemory $vectorMemoryModel
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
    public function getVectorMemories(AiPreset $preset, ?int $limit = null, array $domains = []): Collection
    {
        $query = $this->vectorMemoryModel
            ->where('preset_id', $preset->id)
            ->orderBy('created_at', 'desc');

        if (!empty($domains)) {
            $query->whereIn('domain', $domains);
        }

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
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

            // Resolve domain filter: config wins; otherwise parse inline prefix
            [$domains, $cleanQuery] = $this->resolveSearchDomains($query, $config);

            if (empty($cleanQuery)) {
                return [
                    'success' => false,
                    'message' => 'Error: Search query cannot be empty after parsing domain prefix.'
                ];
            }

            $memories = $this->getVectorMemories($preset, null, $domains);

            if ($memories->isEmpty()) {
                $where = empty($domains) ? '' : ' in [' . implode(', ', $domains) . ']';
                return [
                    'success'  => true,
                    'message'  => "No memories found{$where}.",
                    'results'  => []
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

        // Inline prefix: "domain:a,b | rest"
        if (preg_match('/^domain\s*:\s*([^|]+)\|(.+)$/iu', $query, $m)) {
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
}

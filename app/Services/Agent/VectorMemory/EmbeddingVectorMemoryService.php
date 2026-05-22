<?php

namespace App\Services\Agent\VectorMemory;

use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use App\Contracts\Agent\Search\SearchDateParserInterface;
use App\Models\AiPreset;
use App\Models\VectorMemory;
use App\Services\Agent\Capabilities\Embedding\EmbeddingService;
use Psr\Log\LoggerInterface;

/**
 * Vector memory driver using dense embeddings for semantic search.
 *
 * Extends VectorMemoryService (TF-IDF) with:
 *  - Embedding generation on store via EmbeddingService
 *  - Cosine similarity search over stored dense vectors
 *  - Automatic TF-IDF fallback when embedding is unavailable or fails
 *  - backfillEmbeddings() for migrating existing records
 *
 * Existing records without an embedding column are seamlessly handled
 * via TF-IDF fallback — no data migration required to start using this driver.
 *
 * Register as DRIVER_EMBEDDING in VectorMemoryFactory.
 * The preset passed to each method is also used to resolve the embedding
 * provider config from preset_capability_configs.
 *
 * Domain support (inherited from base):
 *   - storeVectorMemory: $config['domain'] is honoured by parent before embedding is attached
 *   - searchVectorMemories: domain filter is resolved before query embedding, so the
 *     cosine comparisons only run over the filtered subset — saves CPU on irrelevant records
 */
class EmbeddingVectorMemoryService extends VectorMemoryService
{
    public function __construct(
        LoggerInterface $logger,
        TfIdfServiceInterface $tfIdfService,
        VectorMemoryImporter $importer,
        VectorMemoryExporter $exporter,
        VectorMemory $vectorMemoryModel,
        SearchDateParserInterface $searchDateParser,
        protected EmbeddingService $embeddingService,
    ) {
        parent::__construct($logger, $tfIdfService, $importer, $exporter, $vectorMemoryModel, $searchDateParser);
    }

    /**
     * Store content and attach an embedding vector.
     *
     * TF-IDF vector is always computed for backward compatibility and fallback.
     * Embedding is computed synchronously — dispatch a job here if latency matters.
     *
     * Domain handling is delegated to parent::storeVectorMemory().
     *
     * {@inheritDoc}
     */
    public function storeVectorMemory(AiPreset $preset, string $content, array $config = []): array
    {
        $result = parent::storeVectorMemory($preset, $content, $config);

        if (!$result['success'] || !isset($result['memory'])) {
            return $result;
        }

        $attached = $this->attachEmbedding($result['memory'], $content, $preset);

        return array_merge($result, ['has_embedding' => $attached]);
    }

    /**
     * Semantic search using cosine similarity over dense embedding vectors.
     *
     * Algorithm:
     *  1. Peel inline prefixes ("domain:", "time:", or bare keyword) plus any
     *     RAG-config filters into [$domains, $from, $to, $cleanQuery].
     *  2. Load the candidate set scoped to those filters.
     *  3. If $cleanQuery is empty but a time filter is set, return chronological
     *     listing (temporal mode) — no embedding work needed.
     *  4. Otherwise: embed $cleanQuery via EmbeddingService; cosine over
     *     records that have an embedding; TF-IDF supplement for those that
     *     don't; merge and trim to top-K.
     *
     * {@inheritDoc}
     */
    public function searchVectorMemories(AiPreset $preset, string $query, array $config = []): array
    {
        try {
            $query = trim($query);
            if ($query === '') {
                return ['success' => false, 'message' => 'Error: Search query cannot be empty.'];
            }

            [$domains, $from, $to, $cleanQuery] = $this->peelSearchPrefixes($query, $config);

            $hasTimeFilter = ($from !== null || $to !== null);

            if (empty($cleanQuery) && !$hasTimeFilter) {
                return [
                    'success' => false,
                    'message' => 'Error: Search query cannot be empty after parsing prefixes.'
                ];
            }

            $memQuery = new VectorMemoryQuery(
                domains: $domains,
                from:    $from,
                to:      $to,
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

            // Temporal mode: chronological listing, no embedding round-trip.
            if (empty($cleanQuery)) {
                $limit  = $config['search_limit'] ?? 5;
                $sliced = $memories->take($limit);

                $results = $sliced->map(fn (VectorMemory $m) => [
                    'document'   => $m,
                    'memory'     => $m,
                    'similarity' => 1.0,
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
                    'embedding_used' => false,
                ];
            }

            $searchLimit = $config['search_limit'] ?? 5;
            $threshold   = $config['similarity_threshold'] ?? 0.2;

            // Try embedding search first
            $queryEmbedding = $this->embeddingService->embed($cleanQuery, $preset);

            if ($queryEmbedding === null) {
                $this->logger->info('EmbeddingVectorMemoryService: falling back to TF-IDF.', [
                    'preset_id' => $preset->id,
                ]);
                // Parent fallback handles its own filter resolution; we pass the
                // already-peeled clean query and inject resolved filters into config
                // so the parent doesn't re-parse them.
                $fallbackConfig = array_merge($config, [
                    'domains' => $domains,
                    'from'    => $from,
                    'to'      => $to,
                ]);
                return parent::searchVectorMemories($preset, $cleanQuery, $fallbackConfig);
            }

            $withEmbedding    = $memories->filter(fn ($m) => !empty($m->embedding));
            $withoutEmbedding = $memories->filter(fn ($m) => empty($m->embedding));

            // Cosine similarity over records with embedding
            $results = [];

            foreach ($withEmbedding as $memory) {
                $similarity = $this->embeddingService->cosineSimilarity(
                    $queryEmbedding,
                    $memory->embedding,
                );

                if ($similarity >= $threshold) {
                    $results[] = [
                        'document'   => $memory,
                        'memory'     => $memory,
                        'similarity' => $similarity,
                        'source'     => 'embedding',
                    ];
                }
            }

            usort($results, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
            $results = array_slice($results, 0, $searchLimit);

            // Supplement with TF-IDF for records missing an embedding
            if ($withoutEmbedding->isNotEmpty() && count($results) < $searchLimit) {
                $seenIds   = array_map(fn ($r) => $r['memory']->id, $results);
                $remaining = $searchLimit - count($results);

                $tfidfResults = $this->tfIdfService->findSimilar(
                    $cleanQuery,
                    $withoutEmbedding,
                    $remaining,
                    $config['similarity_threshold'] ?? 0.1,
                    $config['boost_recent'] ?? true,
                );

                foreach ($tfidfResults as $r) {
                    if (!in_array($r['document']->id, $seenIds, true)) {
                        $results[] = array_merge($r, [
                            'memory' => $r['document'],
                            'source' => 'tfidf_fallback',
                        ]);
                    }
                }
            }

            usort($results, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
            $results = array_slice($results, 0, $searchLimit);

            return [
                'success'        => true,
                'message'        => 'Found ' . count($results) . ' memories via embedding search.',
                'results'        => $results,
                'total_searched' => $memories->count(),
                'embedding_used' => true,
                'domains'        => $domains,
                'from'           => $from,
                'to'             => $to,
                'temporal'       => false,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('EmbeddingVectorMemoryService::searchVectorMemories error: ' . $e->getMessage());
            return parent::searchVectorMemories($preset, $query, $config);
        }
    }

    /**
     * Compute and attach embeddings to existing records that lack one.
     * Run via an artisan command or queued job for bulk migration.
     *
     * @param  AiPreset  $preset
     * @param  int       $batchSize  Records to process per call.
     * @return array{processed: int, failed: int, remaining: int}
     */
    public function backfillEmbeddings(AiPreset $preset, int $batchSize = 50): array
    {
        if (!$this->embeddingService->isAvailable($preset)) {
            return ['processed' => 0, 'failed' => 0, 'remaining' => 0,
                    'error' => 'No active embedding capability config for this preset.'];
        }

        $memories = $this->vectorMemoryModel
            ->where('preset_id', $preset->id)
            ->whereNull('embedding')
            ->limit($batchSize)
            ->get();

        $processed = $failed = 0;

        foreach ($memories as $memory) {
            $this->attachEmbedding($memory, $memory->content, $preset)
                ? $processed++
                : $failed++;
        }

        $remaining = $this->vectorMemoryModel
            ->where('preset_id', $preset->id)
            ->whereNull('embedding')
            ->count();

        $this->logger->info('EmbeddingVectorMemoryService: backfill batch done.', [
            'preset_id' => $preset->id,
            'processed' => $processed,
            'failed'    => $failed,
            'remaining' => $remaining,
        ]);

        return compact('processed', 'failed', 'remaining');
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Fetch and persist an embedding vector for a single memory record.
     *
     * @return bool  True if embedding was successfully attached.
     */
    private function attachEmbedding(VectorMemory $memory, string $content, AiPreset $preset): bool
    {
        try {
            $vector = $this->embeddingService->embed($content, $preset);

            if ($vector === null) {
                return false;
            }

            $memory->update([
                'embedding'     => $vector,
                'embedding_dim' => count($vector),
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->logger->warning('EmbeddingVectorMemoryService: failed to attach embedding.', [
                'memory_id' => $memory->id,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }
}

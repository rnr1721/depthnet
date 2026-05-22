<?php

namespace App\Services\Agent\VectorMemory;

use App\Models\AiPreset;
use App\Models\VectorMemory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Embedding-based associative vector memory service.
 *
 * Combines dense embedding cosine search with graph-based chain traversal.
 * At 1000+ records this significantly outperforms TF-IDF associative search
 * because hops follow semantic similarity rather than keyword overlap.
 *
 * Algorithm:
 *  1. Resolve domain filter (config wins; otherwise parse inline prefix)
 *  2. Load memories restricted to those domains (or all if none specified)
 *  3. Embed the cleaned query via EmbeddingService
 *  4. Find top-K candidates by cosine similarity (initial retrieval)
 *  5. Build a local similarity graph over the loaded embeddings
 *  6. Walk the graph from top candidates: each hop expands to the
 *     semantically nearest unvisited neighbours
 *  7. Score every visited node: cosine_sim * access_weight * time_decay
 *  8. Return top-K by composite score, update access stats
 *
 * Falls back to EmbeddingVectorMemoryService (no graph) when the preset
 * has no embedding capability configured, and further to TF-IDF when
 * embeddings are fully unavailable.
 *
 * Domain support (inherited from base):
 *   Filtering by domain happens BEFORE graph construction. For a domain
 *   with 200 records the graph is O(200²)=40k pair comparisons instead
 *   of O(1000²)=1M — domains become both a semantic filter and a perf win.
 *   The chain stays scoped to whatever domains the caller asked for.
 */
class EmbeddingAssociativeVectorMemoryService extends EmbeddingVectorMemoryService
{
    // ── Graph parameters ──────────────────────────────────────────────────────

    /**
     * Maximum number of embedding records fed into the local graph.
     * Keeps O(N²) graph construction bounded in memory and time.
     * Records are selected by composite importance * access_count score.
     */
    protected const MAX_GRAPH_NODES = 1000;

    /**
     * Minimum cosine similarity to create an edge in the local graph.
     * Lower values = denser graph = more hops, but slower and noisier.
     */
    protected const GRAPH_EDGE_THRESHOLD = 0.45;

    /**
     * Maximum number of neighbours kept per node.
     * Keeps memory and traversal time bounded at large corpus sizes.
     */
    protected const MAX_NEIGHBOURS_PER_NODE = 20;

    // ── Cache parameters ─────────────────────────────────────────────────────

    /**
     * How long a built graph stays valid in cache.
     * Set to 0 during development/debugging.
     */
    protected const GRAPH_CACHE_TTL_MINUTES = 30;

    /**
     * Prefix for all graph cache keys — makes it easy to monitor/flush.
     */
    protected const GRAPH_CACHE_PREFIX = 'vm_graph';

    // ── Composite scoring (mirrors VectorMemoryAssociativeService) ────────────

    protected const TIME_DECAY_HALF_LIFE_DAYS = 30;
    protected const CHAIN_IMPORTANCE_BOOST    = 0.05;
    protected const MAX_IMPORTANCE            = 5.0;
    protected const SATURATION_PENALTY_FACTOR = 0.5;

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * Overridden to invalidate graph cache after storing a new memory.
     */
    public function storeVectorMemory(AiPreset $preset, string $content, array $config = []): array
    {
        $result = parent::storeVectorMemory($preset, $content, $config);

        if ($result['success'] ?? false) {
            $this->invalidateGraphCache($preset);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * Overridden to invalidate graph cache after deleting a memory.
     */
    public function deleteVectorMemory(AiPreset $preset, int $memoryId): array
    {
        $result = parent::deleteVectorMemory($preset, $memoryId);

        if ($result['success'] ?? false) {
            $this->invalidateGraphCache($preset);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * Wipes all memories AND the cached graph — graph nodes would otherwise
     * dangle as references to deleted records.
     */
    public function clearVectorMemories(AiPreset $preset): array
    {
        $result = parent::clearVectorMemories($preset);

        if ($result['success'] ?? false) {
            $this->invalidateGraphCache($preset);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * Bulk deletes records — graph must be invalidated for the same reason
     * as deleteVectorMemory: cached graph would reference vanished IDs.
     */
    public function purgeDomain(AiPreset $preset, string $domain): int
    {
        $deleted = parent::purgeDomain($preset, $domain);

        if ($deleted > 0) {
            $this->invalidateGraphCache($preset);
        }

        return $deleted;
    }

    /**
     * {@inheritDoc}
     *
     * Intentionally does NOT invalidate the graph: dropDomain only renames
     * the `domain` field on existing records. Record IDs, embeddings, and
     * similarity edges remain valid. Only the domain-filter cache key
     * would shift, which is fine — that's a different cache entry.
     */
    public function dropDomain(AiPreset $preset, string $domain, array $config = []): int
    {
        return parent::dropDomain($preset, $domain, $config);
    }

    /**
     * {@inheritDoc}
     *
     * Backfilling embeddings turns records from "graph-ineligible" into
     * "graph-eligible" — the node set changes, the graph must rebuild.
     */
    public function backfillEmbeddings(AiPreset $preset, int $batchSize = 50): array
    {
        $result = parent::backfillEmbeddings($preset, $batchSize);

        if (($result['processed'] ?? 0) > 0) {
            $this->invalidateGraphCache($preset);
        }

        return $result;
    }

    /**
     * Semantic associative search — now with graph caching.
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

            // Temporal mode — no graph needed
            if (empty($cleanQuery)) {
                $limit  = $config['search_limit'] ?? 5;
                $sliced = $memories->take($limit);

                $results = $sliced->map(fn (VectorMemory $m) => [
                    'document'        => $m,
                    'memory'          => $m,
                    'similarity'      => 1.0,
                    'composite_score' => 1.0,
                    'source'          => 'temporal',
                ])->all();

                $this->updateAccessStats(array_column($results, 'memory'));

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
            $chainDepth  = $config['chain_depth']  ?? 3;
            $threshold   = $config['similarity_threshold'] ?? 0.2;

            // ── Step 1: embed the query ──────────────────────────────────────
            $queryEmbedding = $this->embeddingService->embed($cleanQuery, $preset);

            $fallbackConfig = array_merge($config, [
                'domains' => $domains,
                'from'    => $from,
                'to'      => $to,
            ]);

            if ($queryEmbedding === null) {
                $this->logger->info('EmbeddingAssociativeVectorMemoryService: no embedding — falling back.', [
                    'preset_id' => $preset->id,
                ]);
                return $this->fallbackSearch($preset, $cleanQuery, $fallbackConfig);
            }

            $withEmbedding    = $memories->filter(fn ($m) => !empty($m->embedding))->values();
            $withoutEmbedding = $memories->filter(fn ($m) => empty($m->embedding))->values();

            if ($withEmbedding->isEmpty()) {
                return $this->fallbackSearch($preset, $cleanQuery, $fallbackConfig);
            }

            // Trim to MAX_GRAPH_NODES
            if ($withEmbedding->count() > self::MAX_GRAPH_NODES) {
                $withEmbedding = $withEmbedding
                    ->sortByDesc(fn ($m) => ($m->importance ?? 1.0) * (1 + ($m->access_count ?? 0)))
                    ->take(self::MAX_GRAPH_NODES)
                    ->values();
            }

            // ── Step 2: initial cosine retrieval ─────────────────────────────
            $initialScores = $this->computeCosineScores($queryEmbedding, $withEmbedding);
            arsort($initialScores);

            $seedIndices = array_keys(
                array_filter($initialScores, fn ($s) => $s >= $threshold)
            );

            if (empty($seedIndices)) {
                return $this->buildResultWithTfIdfSupplement(
                    [],
                    $withoutEmbedding,
                    $cleanQuery,
                    $searchLimit,
                    $config
                );
            }

            $seedIndices = array_slice($seedIndices, 0, $searchLimit);

            // ── Step 3: build (or retrieve from cache) local graph ───────────
            $adj = $this->getOrBuildGraph($withEmbedding, $preset, $domains, $from, $to);

            // ── Step 4: graph walk ───────────────────────────────────────────
            $visited  = [];
            $frontier = $seedIndices;

            foreach ($seedIndices as $idx) {
                $memory        = $withEmbedding[$idx];
                $visited[$idx] = $this->compositeScore(
                    $initialScores[$idx],
                    $memory->access_count ?? 0,
                    $memory->last_accessed_at,
                    $memory->created_at,
                );
            }

            for ($hop = 0; $hop < $chainDepth; $hop++) {
                $nextFrontier = [];

                foreach ($frontier as $nodeIdx) {
                    $neighbours = $adj[$nodeIdx] ?? [];

                    foreach ($neighbours as $edge) {
                        $nIdx   = $edge['index'];
                        $weight = $edge['weight'];

                        if (isset($visited[$nIdx])) {
                            continue;
                        }

                        $memory    = $withEmbedding[$nIdx];
                        $propScore = $visited[$nodeIdx] * $weight;
                        $score     = $this->compositeScore(
                            $propScore,
                            $memory->access_count ?? 0,
                            $memory->last_accessed_at,
                            $memory->created_at,
                        );

                        $visited[$nIdx] = $score;
                        $nextFrontier[] = $nIdx;
                    }
                }

                if (empty($nextFrontier)) {
                    break;
                }

                $frontier = $nextFrontier;
            }

            // ── Step 5: rank and assemble results ────────────────────────────
            arsort($visited);

            $results = [];
            foreach (array_slice($visited, 0, $searchLimit, true) as $idx => $score) {
                $memory    = $withEmbedding[$idx];
                $results[] = [
                    'document'        => $memory,
                    'memory'          => $memory,
                    'similarity'      => $initialScores[$idx] ?? null,
                    'composite_score' => $score,
                    'source'          => isset($initialScores[$idx]) ? 'embedding_graph' : 'embedding_graph_hop',
                ];
            }

            // ── Step 5.5: cross-domain bridge hop (optional) ─────────────────────
            $crossDomainResults = [];

            if ($config['cross_domain_bridges'] ?? false) {
                // Only makes sense when domain filter is active AND we have results
                if (!empty($domains) && count($results) > 0) {
                    // Build anchor list: for each top result, locate its index inside
                    // $withEmbedding (we need the embedding) AND carry its composite_score
                    // (so bridge scores propagate correctly from the actual anchor).
                    //
                    // Bug fix: previously this code mixed two different index spaces —
                    // position in $results (0..K) vs index in $withEmbedding (0..N).
                    // Now we keep them as named fields and never confuse them.
                    $anchors = [];
                    foreach ($results as $r) {
                        if (count($anchors) >= 3) {
                            break;
                        }
                        $memId  = $r['memory']->id;
                        $weIdx  = $withEmbedding->search(fn ($m) => $m->id === $memId);
                        if ($weIdx === false) {
                            continue;
                        }
                        $anchors[] = [
                            'we_idx'    => $weIdx,
                            'composite' => $r['composite_score'] ?? $r['similarity'] ?? 0,
                        ];
                    }

                    if (!empty($anchors)) {
                        // Load records from OTHER domains with embeddings
                        $otherMemories = $this->vectorMemoryModel
                            ->where('preset_id', $preset->id)
                            ->whereNotIn('domain', $domains)
                            ->whereNotNull('embedding')
                            ->limit(2000) // safety cap
                            ->get();

                        if ($otherMemories->isNotEmpty()) {
                            $seenBridgeIds = array_map(fn ($r) => $r['memory']->id, $results);

                            foreach ($anchors as $anchor) {
                                $anchorEmb       = $withEmbedding[$anchor['we_idx']]->embedding;
                                $anchorComposite = $anchor['composite'];

                                foreach ($otherMemories as $other) {
                                    if (in_array($other->id, $seenBridgeIds, true)) {
                                        continue;
                                    }

                                    $sim = $this->embeddingService->cosineSimilarity($anchorEmb, $other->embedding);

                                    if ($sim >= self::GRAPH_EDGE_THRESHOLD) {
                                        $crossDomainResults[] = [
                                            'document'        => $other,
                                            'memory'          => $other,
                                            'similarity'      => $sim,
                                            // Propagate anchor's composite × bridge strength —
                                            // strong anchor + strong link = strong bridge score.
                                            'composite_score' => $anchorComposite * $sim,
                                            'source'          => 'cross_domain_bridge',
                                        ];
                                        $seenBridgeIds[] = $other->id;
                                    }
                                }
                            }

                            // Sort by composite score, take top 2 bridges
                            usort($crossDomainResults, fn ($a, $b) => $b['composite_score'] <=> $a['composite_score']);
                            $crossDomainResults = array_slice($crossDomainResults, 0, 2);
                        }
                    }
                }
            }

            // Bridges are an AUGMENTATION, not a search result. We run TF-IDF
            // supplement on the main results first (so the K-budget is spent on
            // the actual semantic core), then append bridges on top.
            $result = $this->buildResultWithTfIdfSupplement(
                $results,
                $withoutEmbedding,
                $cleanQuery,
                $searchLimit,
                $config
            );

            // Append bridges after the main results without competing for the
            // limit budget. Their position in the list signals "extra, related".
            if (!empty($crossDomainResults)) {
                $result['results'] = array_merge($result['results'], $crossDomainResults);
            }

            // ── Step 7: update access stats ──────────────────────────────────
            $this->updateAccessStats(
                collect($result['results'])->pluck('memory')->all()
            );

            return array_merge($result, [
                'total_searched'     => $memories->count(),
                'embedding_used'     => true,
                'graph_nodes'        => count($visited),
                'graph_cached'       => $this->graphWasCached,  // see getOrBuildGraph
                'cross_domain'       => !empty($crossDomainResults),
                'cross_domain_count' => count($crossDomainResults),
                'domains'            => $domains,
                'from'               => $from,
                'to'                 => $to,
                'temporal'           => false,
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('EmbeddingAssociativeVectorMemoryService::search error: ' . $e->getMessage(), [
                'preset_id' => $preset->id,
            ]);
            return $this->fallbackSearch($preset, $query, $config);
        }
    }

    // ── Graph caching ────────────────────────────────────────────────────────

    /**
     * Whether the graph was served from cache in the current search call.
     * Reset before each search; used to populate the result metadata.
     */
    private bool $graphWasCached = false;

    /**
     * Build the local similarity graph or retrieve it from cache.
     *
     * Cache key is deterministic: same preset + same set of record IDs +
     * same domain/time filters → same graph. Adding/removing a record or
     * changing filters produces a different key → fresh build.
     *
     * @param  \Illuminate\Support\Collection  $memories
     * @param  AiPreset                        $preset
     * @param  string[]                        $domains
     * @param  Carbon|null                     $from
     * @param  Carbon|null                     $to
     * @return array<int, array<int, array{index: int, weight: float}>>
     */
    private function getOrBuildGraph(
        \Illuminate\Support\Collection $memories,
        AiPreset                        $preset,
        array                           $domains,
        ?Carbon                         $from,
        ?Carbon                         $to,
    ): array {
        $cacheKey = $this->graphCacheKey($preset, $memories, $domains, $from, $to);

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            $this->graphWasCached = true;

            $this->logger->debug('EmbeddingAssociativeVectorMemoryService: graph cache hit.', [
                'preset_id'   => $preset->id,
                'node_count'  => count($cached),
                'cache_key'   => $cacheKey,
            ]);

            return $cached;
        }

        $this->graphWasCached = false;

        $start  = microtime(true);
        $graph  = $this->buildLocalGraph($memories);
        $elapsed = round((microtime(true) - $start) * 1000, 1);

        Cache::put($cacheKey, $graph, now()->addMinutes(self::GRAPH_CACHE_TTL_MINUTES));

        $this->logger->debug('EmbeddingAssociativeVectorMemoryService: graph built and cached.', [
            'preset_id'   => $preset->id,
            'node_count'  => $memories->count(),
            'edge_count'  => array_sum(array_map('count', $graph)),
            'build_ms'    => $elapsed,
            'cache_key'   => $cacheKey,
        ]);

        return $graph;
    }

    /**
     * Build a deterministic cache key for the graph.
     *
     * Format: vm_graph:{presetId}:v{version}:{domainHash}:{timeHash}:{idsHash}
     *
     * Version is a monotonically increasing integer stored in a separate
     * cache key. Incrementing it (via invalidateGraphCache) effectively
     * invalidates all previous graph keys for this preset without needing
     * cache tags.
     */
    private function graphCacheKey(
        AiPreset                    $preset,
        \Illuminate\Support\Collection $memories,
        array                       $domains,
        ?Carbon                     $from,
        ?Carbon                     $to,
    ): string {
        $version = Cache::get(self::GRAPH_CACHE_PREFIX . "_version:{$preset->id}", 0);

        // Sort IDs for deterministic hash regardless of collection order
        $ids     = $memories->pluck('id')->sort()->values()->toArray();
        $idsHash = md5(implode(',', $ids));

        // Normalise domains: sorted, empty = 'all'
        sort($domains);
        $domainHash = empty($domains) ? 'all' : md5(implode('-', $domains));

        // Normalise time window
        $timeHash = ($from?->toDateString() ?? 'any') . '_' . ($to?->toDateString() ?? 'any');
        $timeHash = md5($timeHash);

        return self::GRAPH_CACHE_PREFIX . ":{$preset->id}:v{$version}:{$domainHash}:{$timeHash}:{$idsHash}";
    }

    /**
     * Invalidate all cached graphs for this preset.
     *
     * Simply increments the version counter. Old cache entries naturally
     * expire after GRAPH_CACHE_TTL_MINUTES and are not accessed because
     * new graph lookups use the new version number.
     */
    private function invalidateGraphCache(AiPreset $preset): void
    {
        $oldVersion = Cache::get(self::GRAPH_CACHE_PREFIX . "_version:{$preset->id}", 0);
        $newVersion = $oldVersion + 1;

        // Store version forever — it's just a tiny integer
        Cache::forever(self::GRAPH_CACHE_PREFIX . "_version:{$preset->id}", $newVersion);

        $this->logger->debug('EmbeddingAssociativeVectorMemoryService: graph cache invalidated.', [
            'preset_id'   => $preset->id,
            'old_version' => $oldVersion,
            'new_version' => $newVersion,
        ]);
    }

    // ── Graph construction ────────────────────────────────────────────────────

    /**
     * Build an in-memory adjacency list from stored embedding vectors.
     *
     * Complexity: O(N²) cosine products — at N=1000 this is ~1M multiplications,
     * which PHP handles in well under 100ms (vectors are already floats[]).
     *
     * Only edges above GRAPH_EDGE_THRESHOLD are kept, and each node retains
     * at most MAX_NEIGHBOURS_PER_NODE edges sorted by weight descending.
     *
     * @param  \Illuminate\Support\Collection $memories  Records with non-null embedding
     * @return array<int, array<int, array{index: int, weight: float}>>
     */
    private function buildLocalGraph(\Illuminate\Support\Collection $memories): array
    {
        $count = $memories->count();
        $vecs  = $memories->map(fn ($m) => $m->embedding)->toArray();
        $adj   = array_fill(0, $count, []);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $sim = $this->embeddingService->cosineSimilarity($vecs[$i], $vecs[$j]);

                if ($sim < self::GRAPH_EDGE_THRESHOLD) {
                    continue;
                }

                $adj[$i][] = ['index' => $j, 'weight' => $sim];
                $adj[$j][] = ['index' => $i, 'weight' => $sim];
            }
        }

        // Sort each adjacency list by weight descending and cap size
        foreach ($adj as &$neighbours) {
            if (count($neighbours) > self::MAX_NEIGHBOURS_PER_NODE) {
                usort($neighbours, fn ($a, $b) => $b['weight'] <=> $a['weight']);
                $neighbours = array_slice($neighbours, 0, self::MAX_NEIGHBOURS_PER_NODE);
            }
        }

        return $adj;
    }

    // ── Scoring ───────────────────────────────────────────────────────────────

    /**
     * Compute cosine similarity between query embedding and all memory embeddings.
     *
     * @param  float[]  $queryEmbedding
     * @param  \Illuminate\Support\Collection  $memories
     * @return array<int, float>  index → similarity
     */
    private function computeCosineScores(array $queryEmbedding, \Illuminate\Support\Collection $memories): array
    {
        $scores = [];

        foreach ($memories as $idx => $memory) {
            $scores[$idx] = $this->embeddingService->cosineSimilarity(
                $queryEmbedding,
                $memory->embedding,
            );
        }

        return $scores;
    }

    /**
     * Composite score: base_score * access_weight * time_weight * saturation_penalty.
     * Mirrors VectorMemoryAssociativeService::computeCompositeScore().
     */
    private function compositeScore(
        float       $baseScore,
        int         $accessCount,
        Carbon|null $lastAccessedAt,
        Carbon|null $createdAt,
    ): float {
        $accessWeight = 1.0 + (log(1 + $accessCount) / log(101));

        if ($lastAccessedAt === null) {
            $timeWeight = 1.0;
        } else {
            $lambda     = log(2) / self::TIME_DECAY_HALF_LIFE_DAYS;
            $daysSince  = now()->diffInHours($lastAccessedAt) / 24.0;
            $timeWeight = max(0.1, exp(-$lambda * $daysSince));
        }

        $ageInDays         = $createdAt ? max(1, $createdAt->diffInDays(now())) : 1;
        $accessRate        = $accessCount / $ageInDays;
        $saturationPenalty = 1.0 / (1.0 + ($accessRate * self::SATURATION_PENALTY_FACTOR));

        return $baseScore * $accessWeight * $timeWeight * $saturationPenalty;
    }

    // ── Access stats ──────────────────────────────────────────────────────────

    /**
     * Increment access_count, update last_accessed_at, and slightly boost importance
     * for memories that acted as graph bridges (long-term potentiation analogy).
     *
     * @param  VectorMemory[]  $memories
     */
    private function updateAccessStats(array $memories): void
    {
        foreach ($memories as $memory) {
            try {
                $memory->update([
                    'access_count'     => ($memory->access_count ?? 0) + 1,
                    'last_accessed_at' => now(),
                    'importance'       => min(
                        self::MAX_IMPORTANCE,
                        ($memory->importance ?? 1.0) + self::CHAIN_IMPORTANCE_BOOST
                    ),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'EmbeddingAssociativeVectorMemoryService: failed to update access stats.',
                    ['memory_id' => $memory->id, 'error' => $e->getMessage()]
                );
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Merge embedding graph results with TF-IDF results for records that
     * have no embedding yet, up to $searchLimit total.
     */
    private function buildResultWithTfIdfSupplement(
        array  $embeddingResults,
        \Illuminate\Support\Collection $withoutEmbedding,
        string $query,
        int    $searchLimit,
        array  $config,
    ): array {
        $results = $embeddingResults;

        if ($withoutEmbedding->isNotEmpty() && count($results) < $searchLimit) {
            $seenIds   = array_map(fn ($r) => $r['memory']->id, $results);
            $remaining = $searchLimit - count($results);

            $tfidf = $this->tfIdfService->findSimilar(
                $query,
                $withoutEmbedding,
                $remaining,
                $config['similarity_threshold'] ?? 0.1,
                $config['boost_recent'] ?? true,
            );

            foreach ($tfidf as $r) {
                if (!in_array($r['document']->id, $seenIds, true)) {
                    $seenIds[] = $r['document']->id; // prevent intra-tfidf dupes
                    $results[] = array_merge($r, [
                        'memory' => $r['document'],
                        'source' => 'tfidf_fallback',
                    ]);
                }
            }
        }

        usort(
            $results,
            fn ($a, $b) =>
            ($b['composite_score'] ?? $b['similarity']) <=> ($a['composite_score'] ?? $a['similarity'])
        );

        return [
            'success' => true,
            'message' => 'Found ' . count($results) . ' memories via embedding-associative search.',
            'results' => array_slice($results, 0, $searchLimit),
        ];
    }

    /**
     * Graceful degradation: try parent (embedding flat search), which itself
     * falls through to TF-IDF if embedding is unavailable.
     *
     * The caller must pass the already-cleaned query — parent will not re-parse
     * the inline "domain:..." prefix (domains travel via $config['domains']).
     */
    private function fallbackSearch(AiPreset $preset, string $query, array $config): array
    {
        return parent::searchVectorMemories($preset, $query, $config);
    }
}

<?php

namespace App\Services\Agent\VectorMemory;

use App\Models\AiPreset;
use App\Models\VectorMemory;
use Carbon\Carbon;

/**
 * Embedding-based associative vector memory service.
 *
 * Combines dense embedding cosine search with graph-based chain traversal.
 * At 1000+ records this significantly outperforms TF-IDF associative search
 * because hops follow semantic similarity rather than keyword overlap.
 *
 * Algorithm:
 *  1. Embed the query via EmbeddingService
 *  2. Find top-K candidates by cosine similarity (initial retrieval)
 *  3. Build a local similarity graph over all loaded embeddings
 *  4. Walk the graph from top candidates: each hop expands to the
 *     semantically nearest unvisited neighbours
 *  5. Score every visited node: cosine_sim * access_weight * time_decay
 *  6. Return top-K by composite score, update access stats
 *
 * Falls back to EmbeddingVectorMemoryService (no graph) when the preset
 * has no embedding capability configured, and further to TF-IDF when
 * embeddings are fully unavailable.
 */
class EmbeddingAssociativeVectorMemoryService extends EmbeddingVectorMemoryService
{
    // ── Graph parameters ──────────────────────────────────────────────────────

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

    // ── Composite scoring (mirrors VectorMemoryAssociativeService) ────────────

    protected const TIME_DECAY_HALF_LIFE_DAYS = 30;
    protected const CHAIN_IMPORTANCE_BOOST    = 0.05;
    protected const MAX_IMPORTANCE            = 5.0;
    protected const SATURATION_PENALTY_FACTOR = 0.5;

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Semantic associative search over dense embedding vectors.
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

            $memories = $this->getVectorMemories($preset);

            if ($memories->isEmpty()) {
                return ['success' => true, 'message' => 'No memories found.', 'results' => []];
            }

            $searchLimit = $config['search_limit'] ?? 5;
            $chainDepth  = $config['chain_depth']  ?? 3;
            $threshold   = $config['similarity_threshold'] ?? 0.2;

            // ── Step 1: embed the query ───────────────────────────────────────
            $queryEmbedding = $this->embeddingService->embed($query, $preset);

            if ($queryEmbedding === null) {
                $this->logger->info('EmbeddingAssociativeVectorMemoryService: no embedding — falling back.', [
                    'preset_id' => $preset->id,
                ]);
                // Graceful degradation chain: embedding → associative TF-IDF
                return $this->fallbackSearch($preset, $query, $config);
            }

            // Split records: only those with a stored embedding participate in
            // graph traversal; the rest are handled by TF-IDF supplement below.
            $withEmbedding    = $memories->filter(fn ($m) => !empty($m->embedding))->values();
            $withoutEmbedding = $memories->filter(fn ($m) => empty($m->embedding))->values();

            if ($withEmbedding->isEmpty()) {
                return $this->fallbackSearch($preset, $query, $config);
            }

            // ── Step 2: initial cosine retrieval ─────────────────────────────
            $initialScores = $this->computeCosineScores($queryEmbedding, $withEmbedding);

            // Sort descending by raw cosine similarity
            arsort($initialScores);

            // Seed nodes = top candidates above threshold
            $seedIndices = array_keys(
                array_filter($initialScores, fn ($s) => $s >= $threshold)
            );

            if (empty($seedIndices)) {
                // Nothing above threshold — supplement with TF-IDF and return
                return $this->buildResultWithTfIdfSupplement(
                    [],
                    $withoutEmbedding,
                    $query,
                    $searchLimit,
                    $config
                );
            }

            // Keep only top searchLimit seeds to start traversal
            $seedIndices = array_slice($seedIndices, 0, $searchLimit);

            // ── Step 3: build local similarity graph ─────────────────────────
            // Graph is built once and reused across all hops.
            // adj[i] = [ [index => j, weight => float], ... ] sorted by weight desc
            $adj = $this->buildLocalGraph($withEmbedding);

            // ── Step 4: graph walk ────────────────────────────────────────────
            $visited    = [];  // index → composite score
            $frontier   = $seedIndices;

            // Seed nodes get their initial cosine score
            foreach ($seedIndices as $idx) {
                $memory  = $withEmbedding[$idx];
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
                        // Propagated score: parent composite * edge weight
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

            // ── Step 5: rank and assemble results ─────────────────────────────
            arsort($visited);

            $results = [];
            foreach (array_slice($visited, 0, $searchLimit, true) as $idx => $score) {
                $memory    = $withEmbedding[$idx];
                $results[] = [
                    'document'        => $memory,
                    'memory'          => $memory,
                    'similarity'      => $initialScores[$idx] ?? null, // null for graph-hop nodes — not a cosine score
                    'composite_score' => $score,
                    'source'          => isset($initialScores[$idx]) ? 'embedding_graph' : 'embedding_graph_hop',
                ];
            }

            // ── Step 6: TF-IDF supplement for records without embedding ───────
            $result = $this->buildResultWithTfIdfSupplement(
                $results,
                $withoutEmbedding,
                $query,
                $searchLimit,
                $config
            );

            // ── Step 7: update access stats ───────────────────────────────────
            $this->updateAccessStats(
                collect($result['results'])->pluck('memory')->all()
            );

            return array_merge($result, [
                'total_searched'  => $memories->count(),
                'embedding_used'  => true,
                'graph_nodes'     => count($visited),
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('EmbeddingAssociativeVectorMemoryService::search error: ' . $e->getMessage(), [
                'preset_id' => $preset->id,
            ]);
            return $this->fallbackSearch($preset, $query, $config);
        }
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
     * Graceful degradation: try parent (embedding flat search), then TF-IDF associative.
     */
    private function fallbackSearch(AiPreset $preset, string $query, array $config): array
    {
        return parent::searchVectorMemories($preset, $query, $config);
    }
}

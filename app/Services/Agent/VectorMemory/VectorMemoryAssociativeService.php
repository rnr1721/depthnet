<?php

namespace App\Services\Agent\VectorMemory;

use App\Models\AiPreset;
use App\Models\VectorMemory;

/**
 * Associative vector memory service.
 *
 * Extends base TF-IDF search with:
 * - Composite scoring: tfidf * log(1 + access_count) * time_decay(last_accessed_at)
 * - Associative chain traversal: top result seeds the next search step
 * - Access tracking: each touched memory gets access_count++ and last_accessed_at update
 * - Importance reinforcement: memories that act as "bridges" gain importance over time
 * - Smart cleanup: removes lowest composite score first, not oldest
 */
class VectorMemoryAssociativeService extends VectorMemoryService
{
    /**
     * Time decay half-life in days.
     * Memory accessed N days ago retains exp(-N/HALF_LIFE * ln2) of its time weight.
     */
    protected const TIME_DECAY_HALF_LIFE_DAYS = 30;

    /**
     * Importance boost applied to each memory touched during associative chain traversal.
     */
    protected const CHAIN_IMPORTANCE_BOOST = 0.05;

    /**
     * Maximum importance value a memory can reach.
     */
    protected const MAX_IMPORTANCE = 5.0;

    /**
     * Search with associative chain traversal and composite scoring.
     *
     * Steps:
     * 1. Search memories using TF-IDF similarity for the original query
     * 2. Apply composite score = tfidf * access_weight * time_weight
     * 3. Take the top result, use its content to seed next search step
     * 4. Repeat for configured chain depth, avoiding already-visited memories
     * 5. Update access stats for all touched memories
     * 6. Return all unique results sorted by composite score descending
     *
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

            $memories = $this->getVectorMemories($preset);

            if ($memories->isEmpty()) {
                return [
                    'success' => true,
                    'message' => 'No memories found. Store some content first.',
                    'results' => []
                ];
            }

            $searchLimit  = $config['search_limit'] ?? 5;
            $threshold    = $config['similarity_threshold'] ?? 0.1;
            $chainDepth   = $config['chain_depth'] ?? 3;

            $visitedIds   = [];
            $chainResults = [];
            $currentQuery = $query;

            for ($step = 0; $step < $chainDepth; $step++) {
                // Filter out already-visited memories
                $remaining = $memories->filter(
                    fn (VectorMemory $m) => !in_array($m->id, $visitedIds)
                );

                if ($remaining->isEmpty()) {
                    break;
                }

                // Raw TF-IDF similarity results
                $stepResults = $this->tfIdfService->findSimilar(
                    $currentQuery,
                    $remaining,
                    $searchLimit,
                    $threshold,
                    false // we handle recency ourselves via composite score
                );

                if (empty($stepResults)) {
                    break;
                }

                // Apply composite scoring and collect results
                foreach ($stepResults as $result) {
                    /** @var VectorMemory $memory */
                    $memory = $result['memory'];

                    if (in_array($memory->id, $visitedIds)) {
                        continue;
                    }

                    $compositeScore = $this->computeCompositeScore(
                        $result['similarity'],
                        $memory->access_count ?? 0,
                        $memory->last_accessed_at
                    );

                    $chainResults[] = array_merge($result, [
                        'composite_score' => $compositeScore,
                        'chain_step'      => $step,
                    ]);

                    $visitedIds[] = $memory->id;
                }

                // The top result of this step seeds the next associative hop
                $topResult    = $stepResults[0]['memory'];
                $currentQuery = $topResult->content;
            }

            if (empty($chainResults)) {
                return [
                    'success' => true,
                    'message' => 'No similar memories found.',
                    'results' => []
                ];
            }

            // Sort all collected results by composite score descending
            usort($chainResults, fn ($a, $b) => $b['composite_score'] <=> $a['composite_score']);

            // Trim to requested limit
            $finalResults = array_slice($chainResults, 0, $searchLimit);

            // Update access stats for all touched memories
            $this->updateAccessStats(
                collect($finalResults)->pluck('memory')->all()
            );

            return [
                'success'        => true,
                'message'        => "Found " . count($finalResults) . " memories via associative search (chain depth: {$chainDepth}).",
                'results'        => $finalResults,
                'total_searched' => $memories->count(),
                'chain_steps'    => $step,
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryAssociativeService::searchVectorMemories error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error searching memories: " . $e->getMessage()
            ];
        }
    }

    /**
     * Compute composite relevance score.
     *
     * Formula: tfidf_score * access_weight * time_weight
     *
     * access_weight = log(1 + access_count) normalized to [1, 2]
     *   - New memory (0 accesses) → weight 1.0 (no penalty)
     *   - Frequently accessed memory → up to 2.0x boost
     *
     * time_weight = exponential decay based on days since last access.
     *   - Never accessed / just stored → weight 1.0
     *   - Half-life = TIME_DECAY_HALF_LIFE_DAYS days
     *
     * @param float $tfidfScore Raw cosine similarity from TF-IDF
     * @param int $accessCount Number of times this memory was accessed
     * @param \Carbon\Carbon|null $lastAccessedAt Timestamp of last access
     * @return float Composite score in range [0, ~2]
     */
    protected function computeCompositeScore(
        float $tfidfScore,
        int $accessCount,
        $lastAccessedAt
    ): float {
        // Access weight: logarithmic growth, normalized to [1.0, 2.0]
        // log(1) = 0 → 1.0, log(101) ≈ 4.6 → ≈ 2.0 at 100 accesses
        $accessWeight = 1.0 + (log(1 + $accessCount) / log(101));

        // Time weight: exponential decay
        if ($lastAccessedAt === null) {
            $timeWeight = 1.0;
        } else {
            $daysSinceAccess = now()->diffInHours($lastAccessedAt) / 24.0;
            $lambda          = log(2) / self::TIME_DECAY_HALF_LIFE_DAYS;
            $timeWeight      = exp(-$lambda * $daysSinceAccess);

            // Floor at 0.1 so even old memories can surface if highly relevant
            $timeWeight = max(0.1, $timeWeight);
        }

        return $tfidfScore * $accessWeight * $timeWeight;
    }

    /**
     * Update access statistics for a set of memories.
     * Also applies a small importance boost — memories acting as associative bridges
     * gradually become more consolidated, like long-term potentiation.
     *
     * @param VectorMemory[] $memories
     * @return void
     */
    protected function updateAccessStats(array $memories): void
    {
        foreach ($memories as $memory) {
            try {
                $newImportance = min(
                    self::MAX_IMPORTANCE,
                    ($memory->importance ?? 1.0) + self::CHAIN_IMPORTANCE_BOOST
                );

                $memory->update([
                    'access_count'    => ($memory->access_count ?? 0) + 1,
                    'last_accessed_at' => now(),
                    'importance'      => $newImportance,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    "VectorMemoryAssociativeService: failed to update access stats for memory {$memory->id}: "
                    . $e->getMessage()
                );
            }
        }
    }

    /**
     * Smart cleanup: removes memories with the lowest composite score first.
     *
     * A memory with low importance, zero access count, and old last_accessed_at
     * is the weakest link — it should be forgotten before a frequently-used
     * memory, even if it was stored more recently.
     *
     * Composite cleanup score = importance * log(1 + access_count) * time_weight
     *
     * @inheritDoc
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

        // Load all memories and score them
        $memories = $this->vectorMemoryModel
            ->where('preset_id', $preset->id)
            ->get(['id', 'importance', 'access_count', 'last_accessed_at']);

        $scored = $memories->map(function (VectorMemory $memory) {
            $importance  = $memory->importance ?? 1.0;
            $accessBoost = log(1 + ($memory->access_count ?? 0));

            if ($memory->last_accessed_at === null) {
                $timeWeight = 0.5; // Never accessed — moderate penalty
            } else {
                $daysSince  = now()->diffInHours($memory->last_accessed_at) / 24.0;
                $lambda     = log(2) / self::TIME_DECAY_HALF_LIFE_DAYS;
                $timeWeight = max(0.01, exp(-$lambda * $daysSince));
            }

            return [
                'id'    => $memory->id,
                'score' => $importance * (1 + $accessBoost) * $timeWeight,
            ];
        })->sortBy('score'); // ascending — weakest first

        $idsToDelete = $scored->take($deleteCount)->pluck('id')->toArray();

        $this->vectorMemoryModel->whereIn('id', $idsToDelete)->delete();

        $this->logger->info(
            "VectorMemoryAssociativeService: cleaned up {$deleteCount} weakest memories for preset {$preset->id}"
        );
    }
}

<?php

namespace App\Services\Agent\VectorMemory;

use App\Models\AiPreset;
use App\Models\VectorMemory;
use Carbon\Carbon;

/**
 * Associative vector memory service.
 *
 * Extends base TF-IDF search with:
 * - Composite scoring: tfidf * log(1 + access_count) * time_decay(last_accessed_at)
 * - Associative chain traversal: top result seeds the next search step
 * - Access tracking: each touched memory gets access_count++ and last_accessed_at update
 * - Importance reinforcement: memories that act as "bridges" gain importance over time
 * - Smart cleanup: removes lowest composite score first, not oldest
 *
 * Domain support (inherited from base):
 *   When a domain filter is applied (via $config['domains'] or inline
 *   "domain:..." prefix), the entire associative chain runs inside the
 *   filtered set. Domains never re-enter the chain via hops — the chain
 *   stays scoped to whatever the caller asked for.
 *
 *   This gives a useful semantic effect: "associations within a context"
 *   — the chain is free to follow meaning, but bounded by the domain.
 *
 * Pulse support (inherited from base):
 *   pulse:N-M restricts the chain starting set to memories created within
 *   the given circadian range. Hops then walk only inside that subset.
 *   The chain effectively becomes "associations within a part of the day"
 *   — useful for surfacing patterns specific to certain hours.
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
     * Saturation penalty factor to prevent overemphasis on frequently accessed memories.
     * Higher values increase the penalty for high access rates.
     */
    protected const SATURATION_PENALTY_FACTOR = 0.5;

    /**
     * Search with associative chain traversal and composite scoring.
     *
     * Steps:
     * 1. Peel inline prefixes ("domain:", "time:", "pulse:") plus any
     *    RAG-config filters into [$domains, $from, $to, $pulseFrom, $pulseTo, $cleanQuery].
     * 2. Load memories restricted to those domains, time window, AND pulse range.
     *    The filter set pins down the STARTING set for the chain — subsequent
     *    associative hops walk only within these memories.
     * 3. If $cleanQuery is empty but at least one filter is set, return chronological
     *    listing (temporal mode) — no chain walk, no semantic ranking.
     * 4. Otherwise: TF-IDF search seeded by $cleanQuery; top hit seeds the
     *    next hop; repeat for chain_depth steps with composite scoring.
     * 5. Update access stats for all touched memories.
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

            [$domains, $from, $to, $pulseFrom, $pulseTo, $cleanQuery]
                = $this->peelSearchPrefixes($query, $config);

            $hasTimeFilter  = ($from !== null || $to !== null);
            $hasPulseFilter = ($pulseFrom !== null || $pulseTo !== null);
            $hasAnyFilter   = $hasTimeFilter || $hasPulseFilter || !empty($domains);

            if (empty($cleanQuery) && !$hasAnyFilter) {
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
                    'message'  => $this->describeEmptyResult($domains, $from, $to, $pulseFrom, $pulseTo),
                    'results'  => [],
                    'domains'  => $domains,
                    'from'     => $from,
                    'to'       => $to,
                    'pulseFrom' => $pulseFrom,
                    'pulseTo'   => $pulseTo,
                    'temporal' => empty($cleanQuery),
                ];
            }

            // Temporal mode: chronological listing inside the filter window.
            // No chain walk — the question "what was I thinking on Monday morning"
            // is best answered straight, not via associations.
            if (empty($cleanQuery)) {
                $limit  = $config['search_limit'] ?? 5;
                $sliced = $memories->take($limit);

                $results = $sliced->map(fn (VectorMemory $m) => [
                    'document'        => $m,
                    'memory'          => $m,
                    'similarity'      => 1.0,
                    'composite_score' => 1.0,
                    'source'          => 'temporal',
                    'chain_step'      => 0,
                ])->all();

                // Still update access stats — these are real touches
                $this->updateAccessStats(array_column($results, 'memory'));

                return [
                    'success'        => true,
                    'message'        => 'Found ' . count($results) . ' memories in filter window.',
                    'results'        => $results,
                    'total_searched' => $memories->count(),
                    'domains'        => $domains,
                    'from'           => $from,
                    'to'             => $to,
                    'pulseFrom'      => $pulseFrom,
                    'pulseTo'        => $pulseTo,
                    'temporal'       => true,
                ];
            }

            $searchLimit  = $config['search_limit'] ?? 5;
            $threshold    = $config['similarity_threshold'] ?? 0.1;
            $chainDepth   = $config['chain_depth'] ?? 3;

            $visitedIds   = [];
            $chainResults = [];
            $currentQuery = $cleanQuery;
            $step         = 0;

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
                    $memory = $result['document']; // TfIdfDocumentInterface key

                    if (in_array($memory->id, $visitedIds)) {
                        continue;
                    }

                    $compositeScore = $this->computeCompositeScore(
                        $result['similarity'],
                        $memory->access_count ?? 0,
                        $memory->last_accessed_at,
                        $memory->created_at
                    );

                    $chainResults[] = array_merge($result, [
                        'memory'          => $memory, // keep 'memory' alias for external callers
                        'composite_score' => $compositeScore,
                        'chain_step'      => $step,
                    ]);

                    $visitedIds[] = $memory->id;
                }

                // The top result of this step seeds the next associative hop
                $topResult    = $stepResults[0]['document']; // TfIdfDocumentInterface key
                $currentQuery = $topResult->getTextContent(); // use interface method
            }

            if (empty($chainResults)) {
                return [
                    'success'   => true,
                    'message'   => 'No similar memories found.',
                    'results'   => [],
                    'domains'   => $domains,
                    'from'      => $from,
                    'to'        => $to,
                    'pulseFrom' => $pulseFrom,
                    'pulseTo'   => $pulseTo,
                    'temporal'  => false,
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

            $filterNote = $hasAnyFilter ? ' within filter window' : '';
            return [
                'success'        => true,
                'message'        => "Found " . count($finalResults) . " memories via associative search{$filterNote} (chain depth: {$chainDepth}).",
                'results'        => $finalResults,
                'total_searched' => $memories->count(),
                'chain_steps'    => $step,
                'domains'        => $domains,
                'from'           => $from,
                'to'             => $to,
                'pulseFrom'      => $pulseFrom,
                'pulseTo'        => $pulseTo,
                'temporal'       => false,
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
     * @param \Carbon\Carbon|null $createdAt Timestamp of memory creation
     * @return float Composite score in range [0, ~2]
     */
    protected function computeCompositeScore(
        float $tfidfScore,
        int $accessCount,
        Carbon|null $lastAccessedAt = null,
        Carbon|null $createdAt = null
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

        $ageInDays         = $createdAt ? max(1, $createdAt->diffInDays(now())) : 1;
        $accessRate        = ($accessCount ?? 0) / $ageInDays;
        $saturationPenalty = 1.0 / (1.0 + ($accessRate * self::SATURATION_PENALTY_FACTOR));

        return $tfidfScore * $accessWeight * $timeWeight * $saturationPenalty;
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
                    'access_count'     => ($memory->access_count ?? 0) + 1,
                    'last_accessed_at' => now(),
                    'importance'       => $newImportance,
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

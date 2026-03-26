<?php

namespace App\Services\Agent\Capabilities\Embedding;

use App\Contracts\Agent\Capabilities\EmbeddingProviderInterface;
use App\Contracts\Agent\Capabilities\EmbeddingServiceInterface;
use App\Models\AiPreset;
use App\Models\PresetCapabilityConfig;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * High-level embedding service.
 *
 * Acts as the single entry point for all embedding operations.
 * Delegates actual HTTP calls to the provider resolved via EmbeddingRegistry.
 *
 * Responsibilities:
 *  - Text preparation (trim, length cap)
 *  - Result caching (SHA-256 keyed, 24h TTL)
 *  - Smart batch caching (only fetches uncached items)
 *  - Cosine similarity calculation
 *  - Graceful null return on any failure (callers fall back to TF-IDF)
 */
class EmbeddingService implements EmbeddingServiceInterface
{
    private const CACHE_VERSION = 'v1';
    private const CACHE_TTL     = 86400;
    private const MAX_TEXT_LEN  = 8000;

    public function __construct(
        protected EmbeddingRegistry $registry,
        protected Cache $cache,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Get embedding vector for a single text.
     *
     * @param  string        $text
     * @param  AiPreset      $preset  Preset whose capability config is used.
     * @return float[]|null           Null if provider unavailable or request failed.
     */
    public function embed(string $text, AiPreset $preset): ?array
    {
        $text = $this->prepare($text);
        if ($text === '') {
            return null;
        }

        $provider = $this->resolveProvider($preset);
        if ($provider === null) {
            return null;
        }

        $key = $this->cacheKey($text, $provider);

        return $this->cache->remember($key, self::CACHE_TTL, fn () => $provider->embed($text));
    }

    /**
     * Get embedding vectors for multiple texts.
     * Already-cached items are returned without an API call.
     *
     * @param  string[]  $texts
     * @param  AiPreset  $preset
     * @return array<int, float[]|null>
     */
    public function embedBatch(array $texts, AiPreset $preset): array
    {
        if (empty($texts)) {
            return [];
        }

        $provider = $this->resolveProvider($preset);
        if ($provider === null) {
            return array_fill(0, count($texts), null);
        }

        $results  = [];
        $toFetch  = [];
        $cacheMap = []; // original_index => cache_key

        foreach ($texts as $i => $text) {
            $prepared       = $this->prepare($text);
            $key            = $this->cacheKey($prepared, $provider);
            $cacheMap[$i]   = $key;
            $cached         = $this->cache->get($key);

            if ($cached !== null) {
                $results[$i] = $cached;
            } else {
                $toFetch[$i] = $prepared;
            }
        }

        if (!empty($toFetch)) {
            $fetched = $provider->embedBatch(array_values($toFetch));
            $keys    = array_keys($toFetch);

            foreach ($fetched as $j => $vector) {
                $i           = $keys[$j];
                $results[$i] = $vector;

                if ($vector !== null) {
                    $this->cache->put($cacheMap[$i], $vector, self::CACHE_TTL);
                }
            }
        }

        ksort($results);
        return $results;
    }

    /**
     * Cosine similarity between two dense vectors.
     *
     * @param  float[]  $a
     * @param  float[]  $b
     * @return float  Value in [0.0, 1.0]. Returns 0.0 on dimension mismatch.
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $len = count($a);
        if ($len === 0 || $len !== count($b)) {
            return 0.0;
        }

        $dot = $magA = $magB = 0.0;

        for ($i = 0; $i < $len; $i++) {
            $dot  += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }

        $magnitude = sqrt($magA) * sqrt($magB);

        return $magnitude < 1e-10 ? 0.0 : (float) ($dot / $magnitude);
    }

    /**
     * Check whether embedding is available for the given preset.
     */
    public function isAvailable(AiPreset $preset): bool
    {
        return $this->registry->isAvailableForPreset($preset);
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function resolveProvider(AiPreset $preset): ?EmbeddingProviderInterface
    {
        try {
            /** @var EmbeddingProviderInterface */
            return $this->registry->makeForPreset($preset);
        } catch (\Throwable $e) {
            $this->logger->debug('EmbeddingService: provider unavailable — ' . $e->getMessage(), [
                'preset_id' => $preset->id,
            ]);
            return null;
        }
    }

    private function prepare(string $text): string
    {
        $text = trim($text);
        return mb_strlen($text) > self::MAX_TEXT_LEN
            ? mb_substr($text, 0, self::MAX_TEXT_LEN)
            : $text;
    }

    private function cacheKey(string $text, EmbeddingProviderInterface $provider): string
    {
        return sprintf(
            'emb_%s_%s_%s_%s',
            self::CACHE_VERSION,
            $provider->getDriverName(),
            hash('crc32', $provider->getDimension()),
            hash('sha256', $text),
        );
    }
}
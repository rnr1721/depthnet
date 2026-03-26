<?php

namespace App\Contracts\Agent\Capabilities;

use App\Models\AiPreset;

interface EmbeddingServiceInterface
{
    /**
     * Get embedding vector for a single text.
     *
     * @param  string   $text
     * @param  AiPreset $preset
     * @return float[]|null
     */
    public function embed(string $text, AiPreset $preset): ?array;

    /**
     * Get embedding vectors for multiple texts.
     *
     * @param  string[] $texts
     * @param  AiPreset $preset
     * @return array<int, float[]|null>
     */
    public function embedBatch(array $texts, AiPreset $preset): array;

    /**
     * Cosine similarity between two dense vectors.
     *
     * @param  float[] $a
     * @param  float[] $b
     */
    public function cosineSimilarity(array $a, array $b): float;

    /**
     * Check whether embedding is available for the given preset.
     */
    public function isAvailable(AiPreset $preset): bool;
}

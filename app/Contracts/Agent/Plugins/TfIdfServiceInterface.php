<?php

namespace App\Contracts\Agent\Plugins;

use Illuminate\Database\Eloquent\Collection;

interface TfIdfServiceInterface
{
    /**
     * Tokenize and normalize text
     *
     * @param string $text
     * @param string|null $language
     * @return array
     */
    public function tokenize(string $text, ?string $language = null): array;

    /**
     * Generate TF-IDF vector for text
     *
     * @param string $text
     * @return array
     */
    public function vectorize(string $text): array;

    /**
     * Find similar memories using TF-IDF vectors
     *
     * @param string $query
     * @param Collection $memories
     * @param int $limit
     * @param float $threshold
     * @param bool $boostRecent
     * @return array
     */
    public function findSimilar(
        $query,
        $memories,
        int $limit = 5,
        float $threshold = 0.1,
        bool $boostRecent = true
    ): array;

    /**
     * Calculate cosine similarity between two vectors
     *
     * @param array $vector1
     * @param array $vector2
     * @return float
     */
    public function cosineSimilarity(array $vector1, array $vector2): float;
    /**
     * Set language configuration from plugin config
     *
     * @param array $config
     * @return void
     */
    public function setLanguageConfig(array $config): void;

    /**
     * Detect language of text
     *
     * @param string $text
     * @return string
     */
    public function detectLanguage(string $text): string;

    /**
     * Get available languages
     *
     * @return array
     */
    public function getAvailableLanguages(): array;

    /**
     * Get statistics about the vector space
     *
     * @return array
     */
    public function getStatistics(): array;

    /**
     * Clear IDF cache (useful when adding many new documents)
     *
     * @return void
     */
    public function clearCache(): void;

}

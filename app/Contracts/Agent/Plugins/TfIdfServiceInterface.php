<?php

namespace App\Contracts\Agent\Plugins;

use Illuminate\Support\Collection;

interface TfIdfServiceInterface
{
    /**
     * Tokenize and normalize text into stemmed, stop-word-free tokens.
     *
     * @param string      $text
     * @param string|null $language  Force language code, or null to auto-detect
     * @return string[]
     */
    public function tokenize(string $text, ?string $language = null): array;

    /**
     * Build a normalised TF-IDF vector for the given text.
     *
     * @param string $text
     * @return array<string, float>
     */
    public function vectorize(string $text): array;

    /**
     * Find the most similar documents in $documents to $query.
     *
     * Works with any Collection of TfIdfDocumentInterface objects.
     *
     * @param string                                   $query
     * @param Collection<int, TfIdfDocumentInterface>  $documents
     * @param int                                      $limit
     * @param float                                    $threshold   Minimum cosine similarity
     * @param bool                                     $boostRecent Apply recency boost
     * @return array<int, array{document: TfIdfDocumentInterface, similarity: float}>
     */
    public function findSimilar(
        string     $query,
        Collection $documents,
        int        $limit       = 5,
        float      $threshold   = 0.1,
        bool       $boostRecent = true
    ): array;

    /**
     * Cosine similarity between two sparse TF-IDF vectors.
     *
     * @param array<string, float> $vector1
     * @param array<string, float> $vector2
     * @return float  Value in [0, 1]
     */
    public function cosineSimilarity(array $vector1, array $vector2): float;

    /**
     * Merge additional stop words / endings into the language config.
     * Called by plugins that expose custom stop-word settings.
     *
     * @param array $config  ['languages' => ['ru' => ['stop_words' => [...]], ...]]
     */
    public function setLanguageConfig(array $config): void;

    /**
     * Detect the dominant language of the given text.
     *
     * @param string $text
     * @return string  Language code, e.g. 'en' or 'ru'
     */
    public function detectLanguage(string $text): string;

    /**
     * Return available language codes and their display names.
     *
     * @return array<string, string>  code => name
     */
    public function getAvailableLanguages(): array;

    /**
     * Clear the IDF cache.
     * Call this after bulk-importing many documents.
     */
    public function clearCache(): void;
}

<?php

namespace App\Services\Agent\Plugins\Related\VectorMemory;

use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use App\Models\VectorMemory;
use Illuminate\Support\Facades\Cache;

/**
 * TfIdfService class
 *
 * Provides TF-IDF (Term Frequency-Inverse Document Frequency) vectorization
 * and semantic similarity search capabilities.
 */
class TfIdfService implements TfIdfServiceInterface
{
    private array $languageConfig = [];
    private bool $languagesLoaded = false;

    public function __construct()
    {
        // Languages will be loaded on first use
    }

    /**
     * Tokenize and normalize text
     *
     * @param string $text
     * @param string|null $language
     * @return array
     */
    public function tokenize(string $text, ?string $language = null): array
    {
        $this->ensureLanguagesLoaded();

        // Auto-detect language if not specified
        if ($language === null) {
            $language = $this->detectLanguage($text);
        }

        // Convert to lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Remove special characters, keep only letters, numbers, and spaces
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        // Split into words
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Get language-specific stop words
        $stopWords = $this->getStopWords($language);

        // Remove stop words and short words
        $words = array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) > 2 && !in_array($word, $stopWords);
        });

        // Apply language-specific stemming
        $words = array_map(function ($word) use ($language) {
            return $this->stem($word, $language);
        }, $words);

        return array_values($words);
    }

    /**
     * Generate TF-IDF vector for text
     *
     * @param string $text
     * @return array
     */
    public function vectorize(string $text): array
    {
        $words = $this->tokenize($text);

        if (empty($words)) {
            return [];
        }

        $wordCounts = array_count_values($words);
        $totalWords = count($words);
        $vector = [];

        foreach ($wordCounts as $word => $count) {
            $tf = $count / $totalWords; // Term Frequency
            $idf = $this->calculateIdf($word); // Inverse Document Frequency
            $vector[$word] = $tf * $idf;
        }

        // Normalize vector
        return $this->normalizeVector($vector);
    }

    /**
     * Find similar memories using TF-IDF vectors
     *
     * @param string $query
     * @param \Illuminate\Database\Eloquent\Collection $memories
     * @param int $limit
     * @param float $threshold
     * @param bool $boostRecent
     * @return array
     */
    public function findSimilar($query, $memories, int $limit = 5, float $threshold = 0.1, bool $boostRecent = true): array
    {
        $queryVector = $this->vectorize($query);

        if (empty($queryVector)) {
            return [];
        }

        $similarities = [];
        $now = now();

        foreach ($memories as $memory) {
            $memoryVector = $memory->tfidf_vector;

            if (empty($memoryVector)) {
                continue;
            }

            $similarity = $this->cosineSimilarity($queryVector, $memoryVector);

            // Boost recent memories if enabled
            if ($boostRecent) {
                $daysSinceCreated = $now->diffInDays($memory->created_at);
                $recencyBoost = max(0.1, 1 - ($daysSinceCreated / 30)); // Boost fades over 30 days
                $similarity *= $recencyBoost;
            }

            if ($similarity >= $threshold) {
                $similarities[] = [
                    'memory' => $memory,
                    'similarity' => $similarity
                ];
            }
        }

        // Sort by similarity score (descending)
        usort($similarities, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return array_slice($similarities, 0, $limit);
    }

    /**
     * Calculate cosine similarity between two vectors
     *
     * @param array $vector1
     * @param array $vector2
     * @return float
     */
    public function cosineSimilarity(array $vector1, array $vector2): float
    {
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        // Get all unique words from both vectors
        $allWords = array_unique(array_merge(array_keys($vector1), array_keys($vector2)));

        foreach ($allWords as $word) {
            $val1 = $vector1[$word] ?? 0;
            $val2 = $vector2[$word] ?? 0;

            $dotProduct += $val1 * $val2;
            $magnitude1 += $val1 * $val1;
            $magnitude2 += $val2 * $val2;
        }

        // Avoid division by zero
        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0;
        }

        return $dotProduct / (sqrt($magnitude1) * sqrt($magnitude2));
    }

    /**
     * Calculate Inverse Document Frequency for a word
     *
     * @param string $word
     * @return float
     */
    private function calculateIdf(string $word): float
    {
        // Cache IDF values for better performance
        $cacheKey = "idf_{$word}";

        return Cache::remember($cacheKey, 3600, function () use ($word) {
            $totalDocuments = VectorMemory::count();

            if ($totalDocuments == 0) {
                return 1.0;
            }

            // Count documents containing this word
            $documentsWithWord = VectorMemory::whereJsonContains('tfidf_vector', [$word => true])->count();

            if ($documentsWithWord == 0) {
                return 1.0;
            }

            // IDF = log(total_documents / documents_with_word)
            return log($totalDocuments / $documentsWithWord);
        });
    }

    /**
     * Normalize vector to unit length
     *
     * @param array $vector
     * @return array
     */
    private function normalizeVector(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(fn ($val) => $val * $val, $vector)));

        if ($magnitude == 0) {
            return $vector;
        }

        return array_map(fn ($val) => $val / $magnitude, $vector);
    }

    /**
     * Language-aware stemming
     *
     * @param string $word
     * @param string $language
     * @return string
     */
    private function stem(string $word, string $language): string
    {
        $endings = $this->getEndings($language);

        foreach ($endings as $ending) {
            if (strlen($word) > strlen($ending) + 3 && str_ends_with($word, $ending)) {
                return substr($word, 0, -strlen($ending));
            }
        }

        return $word;
    }

    /**
     * Load language configurations from JSON files
     *
     * @return void
     */
    private function loadLanguageData(): void
    {
        if ($this->languagesLoaded) {
            return;
        }

        $this->languagesLoaded = true;
        $languagesPath = base_path('data/plugins/vectormemory/languages');

        // Fallback to hardcoded config if directory doesn't exist
        if (!is_dir($languagesPath)) {
            $this->loadFallbackLanguages();
            return;
        }

        try {
            foreach (glob($languagesPath . '/*.json') as $file) {
                $code = basename($file, '.json');
                $data = json_decode(file_get_contents($file), true);

                if ($data && isset($data['stop_words']) && isset($data['endings'])) {
                    $this->languageConfig[$code] = $data;
                }
            }

            // If no valid files found, use fallback
            if (empty($this->languageConfig)) {
                $this->loadFallbackLanguages();
            }

        } catch (\Exception $e) {
            // On any error, use fallback
            $this->loadFallbackLanguages();
        }
    }

    /**
     * Load fallback language configuration
     *
     * @return void
     */
    private function loadFallbackLanguages(): void
    {
        $this->languageConfig = [
            'ru' => [
                'name' => 'Russian',
                'code' => 'ru',
                'stop_words' => ['и', 'в', 'на', 'что', 'как', 'это', 'для', 'с', 'по', 'от', 'до', 'из',
                               'он', 'она', 'они', 'мы', 'вы', 'я', 'ты', 'то', 'так', 'но', 'или', 'за', 'под'],
                'endings' => ['ение', 'ость', 'ова', 'ева', 'ать', 'ить', 'еть', 'ов', 'ев', 'ий', 'ая', 'ое', 'ые']
            ],
            'en' => [
                'name' => 'English',
                'code' => 'en',
                'stop_words' => ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with',
                               'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did'],
                'endings' => ['tion', 'ing', 'ment', 'ness', 'ful', 'ous', 'ive', 'ed', 'ly', 's', 'er', 'est']
            ]
        ];
    }

    /**
     * Ensure languages are loaded
     *
     * @return void
     */
    private function ensureLanguagesLoaded(): void
    {
        if (!$this->languagesLoaded) {
            $this->loadLanguageData();
        }
    }

    /**
     * Set language configuration from plugin config
     *
     * @param array $config
     * @return void
     */
    public function setLanguageConfig(array $config): void
    {
        $this->ensureLanguagesLoaded();

        if (isset($config['languages'])) {
            foreach ($config['languages'] as $code => $langConfig) {
                if (isset($this->languageConfig[$code])) {
                    // Merge custom stop words with existing ones
                    if (isset($langConfig['stop_words'])) {
                        $this->languageConfig[$code]['stop_words'] = array_merge(
                            $this->languageConfig[$code]['stop_words'],
                            $langConfig['stop_words']
                        );
                    }

                    // Merge other config if needed
                    $this->languageConfig[$code] = array_merge(
                        $this->languageConfig[$code],
                        $langConfig
                    );
                } else {
                    // Add completely new language
                    $this->languageConfig[$code] = $langConfig;
                }
            }
        }
    }

    /**
     * Detect language of text
     *
     * @param string $text
     * @return string
     */
    public function detectLanguage(string $text): string
    {
        $this->ensureLanguagesLoaded();

        $text = mb_strtolower($text, 'UTF-8');

        // Count Cyrillic characters
        $cyrillicCount = preg_match_all('/[\p{Cyrillic}]/u', $text);
        $totalChars = mb_strlen($text, 'UTF-8');

        if ($totalChars == 0) {
            return 'en';
        }

        $cyrillicRatio = $cyrillicCount / $totalChars;

        // Check if we have advanced detection patterns
        foreach ($this->languageConfig as $code => $config) {
            if (isset($config['detection_patterns'])) {
                $patterns = $config['detection_patterns'];

                // Cyrillic detection
                if (isset($patterns['charset']) && $patterns['charset'] === 'cyrillic') {
                    $threshold = $patterns['threshold'] ?? 0.3;
                    if ($cyrillicRatio >= $threshold) {
                        return $code;
                    }
                }

                // Sample words detection
                if (isset($patterns['sample_words']) && is_array($patterns['sample_words'])) {
                    $matchCount = 0;
                    foreach ($patterns['sample_words'] as $word) {
                        if (str_contains($text, $word)) {
                            $matchCount++;
                        }
                    }
                    if ($matchCount >= 2) { // Found at least 2 sample words
                        return $code;
                    }
                }
            }
        }

        // Fallback: If more than 30% Cyrillic characters, assume Russian
        return $cyrillicRatio > 0.3 ? 'ru' : 'en';
    }

    /**
     * Get stop words for language
     *
     * @param string $language
     * @return array
     */
    private function getStopWords(string $language): array
    {
        $this->ensureLanguagesLoaded();
        return $this->languageConfig[$language]['stop_words'] ?? [];
    }

    /**
     * Get word endings for language
     *
     * @param string $language
     * @return array
     */
    private function getEndings(string $language): array
    {
        $this->ensureLanguagesLoaded();
        return $this->languageConfig[$language]['endings'] ?? [];
    }

    /**
     * Get available languages
     *
     * @return array
     */
    public function getAvailableLanguages(): array
    {
        $this->ensureLanguagesLoaded();

        $languages = [];
        foreach ($this->languageConfig as $code => $config) {
            $languages[$code] = $config['name'] ?? ucfirst($code);
        }

        return $languages;
    }

    /**
     * Get statistics about the vector space
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $totalMemories = VectorMemory::count();
        $avgVectorSize = 0;
        $vocabularySize = 0;

        if ($totalMemories > 0) {
            $sample = VectorMemory::take(100)->get();
            $totalFeatures = 0;
            $allWords = [];

            foreach ($sample as $memory) {
                $vector = $memory->tfidf_vector;
                $totalFeatures += count($vector);
                $allWords = array_merge($allWords, array_keys($vector));
            }

            $avgVectorSize = $totalFeatures / count($sample);
            $vocabularySize = count(array_unique($allWords));
        }

        return [
            'total_memories' => $totalMemories,
            'average_vector_size' => round($avgVectorSize, 1),
            'estimated_vocabulary_size' => $vocabularySize,
            'cache_hits' => Cache::get('idf_cache_hits', 0)
        ];
    }

    /**
     * Clear IDF cache (useful when adding many new documents)
     *
     * @return void
     */
    public function clearCache(): void
    {
        Cache::flush(); // This clears all cache, you might want to be more selective
    }
}

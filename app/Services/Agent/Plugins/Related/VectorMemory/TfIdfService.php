<?php

namespace App\Services\Agent\Plugins\Related\VectorMemory;

use App\Contracts\Agent\Plugins\TfIdfDocumentInterface;
use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * TF-IDF vectorisation and cosine-similarity search.
 *
 * Works with any collection of TfIdfDocumentInterface objects —
 * VectorMemory, SkillItem, or anything else that stores a tfidf_vector.
 *
 * The only coupling to a concrete model is in calculateIdf(), and even
 * there the total-document count is passed in by the caller so this
 * class never imports a specific Eloquent model.
 */
class TfIdfService implements TfIdfServiceInterface
{
    private array $languageConfig = [];
    private bool $languagesLoaded = false;

    public function __construct()
    {
        // Languages are loaded lazily on first use
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Tokenize and normalise text into stemmed, stop-word-free tokens.
     *
     * @param string      $text
     * @param string|null $language  Force a language code, or null to auto-detect
     * @return string[]
     */
    public function tokenize(string $text, ?string $language = null): array
    {
        $this->ensureLanguagesLoaded();

        $language = $language ?? $this->detectLanguage($text);

        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $stopWords = $this->getStopWords($language);

        $words = array_filter(
            $words,
            fn (string $w) => mb_strlen($w, 'UTF-8') > 2 && !in_array($w, $stopWords, true)
        );

        $words = array_map(fn (string $w) => $this->stem($w, $language), $words);

        return array_values($words);
    }

    /**
     * Build a normalised TF-IDF vector for the given text.
     *
     * IDF is computed against an in-memory vocabulary only (no DB call here).
     * Pass the result to storeVector() helpers on your model, or use
     * findSimilar() which accepts a pre-loaded collection.
     *
     * @param string $text
     * @return array<string, float>
     */
    public function vectorize(string $text): array
    {
        $words = $this->tokenize($text);

        if (empty($words)) {
            return [];
        }

        $wordCounts = array_count_values($words);
        $totalWords = count($words);
        $vector     = [];

        foreach ($wordCounts as $word => $count) {
            $tf            = $count / $totalWords;
            $idf           = $this->calculateIdf($word);
            $vector[$word] = $tf * $idf;
        }

        return $this->normalizeVector($vector);
    }

    /**
     * Find the most similar documents in $documents to $query.
     *
     * Accepts any Collection of TfIdfDocumentInterface, so it works
     * for VectorMemory, SkillItem, or any future document type.
     *
     * @param string                                    $query
     * @param Collection<int, TfIdfDocumentInterface>  $documents
     * @param int                                       $limit
     * @param float                                     $threshold   Minimum cosine similarity
     * @param bool                                      $boostRecent Apply recency boost
     * @return array<int, array{document: TfIdfDocumentInterface, similarity: float}>
     */
    public function findSimilar(
        string     $query,
        Collection $documents,
        int        $limit       = 5,
        float      $threshold   = 0.1,
        bool       $boostRecent = true
    ): array {
        $queryVector = $this->vectorize($query);

        if (empty($queryVector)) {
            return [];
        }

        $similarities = [];
        $now          = now();

        foreach ($documents as $document) {
            $docVector = $document->getTfIdfVector();

            if (empty($docVector)) {
                continue;
            }

            $similarity = $this->cosineSimilarity($queryVector, $docVector);

            if ($boostRecent && $document->getCreatedAt() !== null) {
                $daysSince  = $now->diffInDays($document->getCreatedAt());
                $recency    = max(0.1, 1 - ($daysSince / 30));
                $similarity *= $recency;
            }

            if ($similarity >= $threshold) {
                $similarities[] = [
                    'document'   => $document,
                    'similarity' => $similarity,
                ];
            }
        }

        usort($similarities, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($similarities, 0, $limit);
    }

    /**
     * Cosine similarity between two sparse vectors.
     *
     * @param array<string, float> $vector1
     * @param array<string, float> $vector2
     * @return float  Value in [0, 1]
     */
    public function cosineSimilarity(array $vector1, array $vector2): float
    {
        $dotProduct = 0.0;
        $magnitude1 = 0.0;
        $magnitude2 = 0.0;

        $allWords = array_unique(array_merge(array_keys($vector1), array_keys($vector2)));

        foreach ($allWords as $word) {
            $v1 = $vector1[$word] ?? 0.0;
            $v2 = $vector2[$word] ?? 0.0;

            $dotProduct += $v1 * $v2;
            $magnitude1 += $v1 * $v1;
            $magnitude2 += $v2 * $v2;
        }

        if ($magnitude1 == 0.0 || $magnitude2 == 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($magnitude1) * sqrt($magnitude2));
    }

    // -------------------------------------------------------------------------
    // Language helpers (public)
    // -------------------------------------------------------------------------

    /**
     * Detect the language of the given text.
     *
     * @param string $text
     * @return string  Language code, e.g. 'en' or 'ru'
     */
    public function detectLanguage(string $text): string
    {
        $this->ensureLanguagesLoaded();

        $lower       = mb_strtolower($text, 'UTF-8');
        $totalChars  = mb_strlen($lower, 'UTF-8');
        $cyrillicCnt = preg_match_all('/[\p{Cyrillic}]/u', $lower);

        if ($totalChars === 0) {
            return 'en';
        }

        $cyrillicRatio = $cyrillicCnt / $totalChars;

        foreach ($this->languageConfig as $code => $config) {
            $patterns = $config['detection_patterns'] ?? [];

            if (isset($patterns['charset']) && $patterns['charset'] === 'cyrillic') {
                $threshold = $patterns['threshold'] ?? 0.3;
                if ($cyrillicRatio >= $threshold) {
                    return $code;
                }
            }

            if (isset($patterns['sample_words']) && is_array($patterns['sample_words'])) {
                $matches = 0;
                foreach ($patterns['sample_words'] as $word) {
                    if (str_contains($lower, $word)) {
                        $matches++;
                    }
                }
                if ($matches >= 2) {
                    return $code;
                }
            }
        }

        return $cyrillicRatio > 0.3 ? 'ru' : 'en';
    }

    /**
     * Merge additional stop words / endings into the loaded language config.
     * Called by plugins that expose custom stop-word settings.
     *
     * @param array $config  ['languages' => ['ru' => ['stop_words' => [...]], ...]]
     */
    public function setLanguageConfig(array $config): void
    {
        $this->ensureLanguagesLoaded();

        foreach ($config['languages'] ?? [] as $code => $langConfig) {
            if (isset($this->languageConfig[$code])) {
                if (isset($langConfig['stop_words'])) {
                    $this->languageConfig[$code]['stop_words'] = array_merge(
                        $this->languageConfig[$code]['stop_words'],
                        $langConfig['stop_words']
                    );
                }
                $this->languageConfig[$code] = array_merge($this->languageConfig[$code], $langConfig);
            } else {
                $this->languageConfig[$code] = $langConfig;
            }
        }
    }

    /**
     * @return array<string, string>  code => name
     */
    public function getAvailableLanguages(): array
    {
        $this->ensureLanguagesLoaded();

        $out = [];
        foreach ($this->languageConfig as $code => $config) {
            $out[$code] = $config['name'] ?? ucfirst($code);
        }
        return $out;
    }

    /**
     * Clear the IDF cache.
     * Call this after bulk-importing many documents.
     */
    public function clearCache(): void
    {
        Cache::flush();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Compute IDF for a single word.
     *
     * IDF is cached per word for one hour.
     * The total-document count is looked up fresh from the cache key
     * "tfidf_total_docs" which callers are expected to prime before
     * a bulk search session (optional optimisation; defaults to 1).
     *
     * Why no Model:: call here?
     * We deliberately avoid importing any Eloquent model so that this
     * service stays decoupled. Callers that want accurate cross-corpus
     * IDF should pre-populate "tfidf_total_docs" in the cache.
     * For single-corpus use the IDF values still rank words correctly
     * relative to each other within the collection passed to findSimilar().
     *
     * @param string $word
     * @return float
     */
    private function calculateIdf(string $word): float
    {
        $cacheKey = "tfidf_idf_{$word}";

        return Cache::remember($cacheKey, 3600, function () use ($word) {
            // Total docs hint — optionally set by callers via Cache::put('tfidf_total_docs', N)
            $totalDocuments = Cache::get('tfidf_total_docs', 1);

            if ($totalDocuments <= 0) {
                return 1.0;
            }

            // Docs-with-word hint — set the same way if you want accurate cross-doc IDF
            $docsWithWord = Cache::get("tfidf_docs_with_{$word}", 0);

            if ($docsWithWord <= 0) {
                return 1.0;
            }

            return log($totalDocuments / $docsWithWord);
        });
    }

    /**
     * L2-normalise a vector to unit length.
     *
     * @param array<string, float> $vector
     * @return array<string, float>
     */
    private function normalizeVector(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));

        if ($magnitude == 0.0) {
            return $vector;
        }

        return array_map(fn ($v) => $v / $magnitude, $vector);
    }

    /**
     * Lightweight suffix-stripping stemmer.
     *
     * @param string $word
     * @param string $language
     * @return string
     */
    private function stem(string $word, string $language): string
    {
        $endings = $this->getEndings($language);
        $len     = mb_strlen($word, 'UTF-8');

        foreach ($endings as $ending) {
            $endLen = mb_strlen($ending, 'UTF-8');
            if ($len > $endLen + 3 && str_ends_with($word, $ending)) {
                return mb_substr($word, 0, $len - $endLen, 'UTF-8');
            }
        }

        return $word;
    }

    private function getStopWords(string $language): array
    {
        $this->ensureLanguagesLoaded();
        return $this->languageConfig[$language]['stop_words'] ?? [];
    }

    private function getEndings(string $language): array
    {
        $this->ensureLanguagesLoaded();
        return $this->languageConfig[$language]['endings'] ?? [];
    }

    private function ensureLanguagesLoaded(): void
    {
        if (!$this->languagesLoaded) {
            $this->loadLanguageData();
        }
    }

    private function loadLanguageData(): void
    {
        $this->languagesLoaded = true;
        $path = base_path('data/plugins/vectormemory/languages');

        if (!is_dir($path)) {
            $this->loadFallbackLanguages();
            return;
        }

        try {
            foreach (glob($path . '/*.json') as $file) {
                $code = basename($file, '.json');
                $data = json_decode(file_get_contents($file), true);

                if ($data && isset($data['stop_words'], $data['endings'])) {
                    $this->languageConfig[$code] = $data;
                }
            }

            if (empty($this->languageConfig)) {
                $this->loadFallbackLanguages();
            }
        } catch (\Exception $e) {
            $this->loadFallbackLanguages();
        }
    }

    private function loadFallbackLanguages(): void
    {
        $this->languageConfig = [
            'ru' => [
                'name'       => 'Russian',
                'code'       => 'ru',
                'stop_words' => ['и', 'в', 'на', 'что', 'как', 'это', 'для', 'с', 'по', 'от', 'до', 'из',
                                 'он', 'она', 'они', 'мы', 'вы', 'я', 'ты', 'то', 'так', 'но', 'или', 'за', 'под'],
                'endings'    => ['ение', 'ость', 'ова', 'ева', 'ать', 'ить', 'еть', 'ов', 'ев', 'ий', 'ая', 'ое', 'ые'],
            ],
            'en' => [
                'name'       => 'English',
                'code'       => 'en',
                'stop_words' => ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with',
                                 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did'],
                'endings'    => ['tion', 'ing', 'ment', 'ness', 'ful', 'ous', 'ive', 'ed', 'ly', 's', 'er', 'est'],
            ],
        ];
    }
}

<?php

namespace App\Services\Agent\Search;

use App\Contracts\Agent\Search\SearchDateParserInterface;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Data-driven, language-agnostic date expression parser for search queries.
 *
 * Vocabulary is loaded from a JSON file at construction time. The path
 * defaults to base_path('data/search/keywords.json') but can be overridden
 * via constructor for tests or alternative deployments.
 *
 * Lookup strategy
 * ---------------
 * All keyword variants across all configured languages are flattened into
 * a single hashmap (variant → canonical key). The parser doesn't know or
 * care which language a variant belongs to — it just resolves tokens.
 * This makes mixed-language queries like "сегодня | meeting notes" work
 * naturally without any language detection step.
 *
 * Collisions
 * ----------
 * If two languages share a literal variant (e.g. "month" and a hypothetical
 * "month" in another language meaning something different), the FIRST one
 * encountered wins. In practice this almost never happens for date words;
 * if it does, the JSON layout makes it easy to spot.
 *
 * Failure mode
 * ------------
 * Missing or malformed JSON is logged at WARNING level and the parser
 * falls back to an empty vocabulary. ISO date formats still work in that
 * case — the parser stays functional, just keyword-deaf.
 */
class SearchDateParser implements SearchDateParserInterface
{
    private const DEFAULT_KEYWORDS_PATH = 'data/search/keywords.json';

    /**
     * Flat lookup: lowercased variant → canonical key.
     *
     * @var array<string, string>
     */
    private array $variantToKey = [];

    /**
     * Canonical key → list of variants (across all languages).
     * Preserved for listKeywords() introspection.
     *
     * @var array<string, string[]>
     */
    private array $keyToVariants = [];

    public function __construct(
        protected LoggerInterface $logger,
        ?string $keywordsPath = null,
    ) {
        $path = $keywordsPath ?? base_path(self::DEFAULT_KEYWORDS_PATH);
        $this->loadVocabulary($path);
    }

    /**
     * @inheritDoc
     */
    public function parse(string $query): ParsedSearchQuery
    {
        $query = trim($query);

        if ($query === '') {
            return new ParsedSearchQuery(null, null, '');
        }

        // Case 1: explicit separator "datePart | semanticPart"
        if (str_contains($query, '|')) {
            [$datePart, $semanticPart] = array_map('trim', explode('|', $query, 2));
            $range = $this->parseDateExpression($datePart);

            if ($range !== null) {
                [$from, $to] = $range;
                return new ParsedSearchQuery($from, $to, $semanticPart);
            }

            // Not a recognised date prefix — pipe is literal content.
            // Return the ORIGINAL query untouched (not the trimmed/split version),
            // so callers downstream see exactly what the agent wrote.
            return new ParsedSearchQuery(null, null, $query);
        }

        // Case 2: whole string is a date expression (e.g. "today", "2026-03-15")
        $range = $this->parseDateExpression($query);
        if ($range !== null) {
            [$from, $to] = $range;
            return new ParsedSearchQuery($from, $to, '');
        }

        // Case 3: no date involved — pure semantic query
        return new ParsedSearchQuery(null, null, $query);
    }

    /**
     * @inheritDoc
     */
    public function parseDateExpression(string $expr): ?array
    {
        $expr = trim(mb_strtolower($expr));

        if ($expr === '') {
            return null;
        }

        // ISO date range: YYYY-MM-DD:YYYY-MM-DD
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*:\s*(\d{4}-\d{2}-\d{2})$/', $expr, $m)) {
            try {
                return [
                    Carbon::parse($m[1])->startOfDay(),
                    Carbon::parse($m[2])->endOfDay(),
                ];
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Single ISO date: YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expr)) {
            try {
                $date = Carbon::parse($expr);
                return [$date->copy()->startOfDay(), $date->copy()->endOfDay()];
            } catch (\Throwable $e) {
                return null;
            }
        }

        // ISO year-month: YYYY-MM → full calendar month
        if (preg_match('/^(\d{4})-(\d{2})$/', $expr, $m)) {
            $year  = (int) $m[1];
            $month = (int) $m[2];
            if ($month < 1 || $month > 12) {
                return null;
            }
            try {
                $start = Carbon::create($year, $month, 1)->startOfMonth();
                return [$start, $start->copy()->endOfMonth()];
            } catch (\Throwable $e) {
                return null;
            }
        }

        // ISO year: YYYY → full calendar year
        if (preg_match('/^(\d{4})$/', $expr, $m)) {
            $year = (int) $m[1];
            // Bounded sanity check — Carbon will accept silly years otherwise.
            // 1900..2200 is plenty for a journal/memory app.
            if ($year < 1900 || $year > 2200) {
                return null;
            }
            try {
                $start = Carbon::create($year, 1, 1)->startOfYear();
                return [$start, $start->copy()->endOfYear()];
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Keyword lookup
        if (isset($this->variantToKey[$expr])) {
            return $this->resolveKeyword($this->variantToKey[$expr]);
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function listKeywords(): array
    {
        return $this->keyToVariants;
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Resolve a canonical keyword key into a Carbon date range.
     *
     * Computed lazily on each call so "today" actually means today at the
     * moment of search, not at the moment of parser construction.
     *
     * Returns null only in the pathological case where the JSON vocabulary
     * references a canonical key the code doesn't recognise (i.e. we added
     * a key to the JSON but forgot to add a match arm here). This is logged.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function resolveKeyword(string $key): ?array
    {
        return match ($key) {
            'today' => [
                Carbon::today()->startOfDay(),
                Carbon::today()->endOfDay(),
            ],
            'yesterday' => [
                Carbon::yesterday()->startOfDay(),
                Carbon::yesterday()->endOfDay(),
            ],
            'last_week' => [
                Carbon::now()->subWeek()->startOfWeek()->startOfDay(),
                Carbon::now()->subWeek()->endOfWeek()->endOfDay(),
            ],
            'this_week' => [
                Carbon::now()->startOfWeek()->startOfDay(),
                Carbon::now()->endOfDay(),
            ],
            'last_month' => [
                Carbon::now()->startOfMonth()->subMonth()->startOfDay(),
                Carbon::now()->startOfMonth()->subMonth()->endOfMonth()->endOfDay(),
            ],
            'this_month' => [
                Carbon::now()->startOfMonth()->startOfDay(),
                Carbon::now()->endOfDay(),
            ],
            'last_year' => [
                Carbon::now()->startOfYear()->subYear()->startOfDay(),
                Carbon::now()->startOfYear()->subYear()->endOfYear()->endOfDay(),
            ],
            'this_year' => [
                Carbon::now()->startOfYear()->startOfDay(),
                Carbon::now()->endOfDay(),
            ],

            // Unknown canonical key — vocabulary has an entry the code doesn't
            // know how to resolve. Should never happen unless JSON references
            // a key we forgot to handle. Log and treat as no match.
            default => $this->logUnknownKey($key),
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null Always null — kept as the return
     * for match() type uniformity. Callers see null via match expression context.
     */
    private function logUnknownKey(string $key): ?array
    {
        $this->logger->warning('SearchDateParser: vocabulary references unknown canonical key.', [
            'key' => $key,
        ]);
        return null;
    }

    /**
     * Load and flatten the JSON keyword vocabulary.
     */
    private function loadVocabulary(string $path): void
    {
        if (!file_exists($path)) {
            $this->logger->warning('SearchDateParser: keywords file not found, only ISO dates will work.', [
                'path' => $path,
            ]);
            return;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->logger->warning('SearchDateParser: could not read keywords file.', [
                'path' => $path,
            ]);
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->logger->warning('SearchDateParser: keywords file is not valid JSON.', [
                'path'  => $path,
                'error' => json_last_error_msg(),
            ]);
            return;
        }

        foreach ($decoded as $canonicalKey => $byLanguage) {
            // Skip metadata keys like "_comment"
            if (str_starts_with((string) $canonicalKey, '_')) {
                continue;
            }

            if (!is_array($byLanguage)) {
                continue;
            }

            $allVariants = [];

            foreach ($byLanguage as $lang => $variants) {
                if (!is_array($variants)) {
                    continue;
                }

                foreach ($variants as $variant) {
                    if (!is_string($variant)) {
                        continue;
                    }

                    $normalised = mb_strtolower(trim($variant));
                    if ($normalised === '') {
                        continue;
                    }

                    $allVariants[] = $normalised;

                    // First variant wins on collision — silently keep the original
                    if (!isset($this->variantToKey[$normalised])) {
                        $this->variantToKey[$normalised] = $canonicalKey;
                    }
                }
            }

            if (!empty($allVariants)) {
                $this->keyToVariants[$canonicalKey] = array_values(array_unique($allVariants));
            }
        }
    }
}

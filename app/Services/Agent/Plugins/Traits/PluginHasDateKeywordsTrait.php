<?php

namespace App\Services\Agent\Plugins\Traits;

use App\Contracts\Agent\Search\SearchDateParserInterface;

/**
 * Shared logic for plugins that show date keyword examples in their
 * instructions and tool schemas.
 *
 * Used by VectorMemoryPlugin, JournalPlugin, and RagQueryPlugin to keep
 * date-related agent-facing copy consistent across the system: same
 * keyword vocabulary, same language filtering, same example formatting.
 *
 * Requirements on the using class:
 *   - protected SearchDateParserInterface $searchDateParser  (from constructor)
 *   - protected array $supportedLanguages                    (from PluginHasLanguageSettingsTrait)
 *
 * Both are conventional fields that already exist in every plugin that
 * needs this behaviour. The trait does not declare them — it only consumes
 * them — to avoid PHP's "duplicate property" errors when used alongside
 * PluginHasLanguageSettingsTrait.
 *
 * If a plugin wants to override the localised semantic-fragment used in
 * date-search examples (e.g. "optimization" vs "оптимизация"), declare a
 * `protected const EXAMPLE_SEMANTIC = [...]` in the using class — the
 * default below is sane for English-leaning installations but is naturally
 * overridable.
 *
 * @property SearchDateParserInterface $searchDateParser
 */
trait PluginHasDateKeywordsTrait
{
    /**
     * Default localised semantic fragment used inside date-search examples.
     * Override in the using class if your domain needs a different word.
     */
    private const DEFAULT_EXAMPLE_SEMANTIC = [
        'en' => 'optimization',
        'ru' => 'оптимизация',
        'de' => 'Optimierung',
        'fr' => 'optimisation',
        'es' => 'optimización',
    ];

    /**
     * Pick a localised variant of a keyword for inline use in an example.
     * Falls back to English variant, then to the canonical key.
     *
     * Example: localisedKeyword('yesterday', 'ru') → 'вчера'
     */
    protected function localisedKeyword(string $canonicalKey, string $lang): string
    {
        $variants = $this->searchDateParser->listKeywords()[$canonicalKey] ?? [];
        if (empty($variants)) {
            return $canonicalKey;
        }

        $filter = $this->resolveLanguagesToShow($lang);
        $picked = $this->pickVariantsForLanguages($variants, $filter);

        return $picked[0] ?? $variants[0];
    }

    /**
     * Pick the example-semantic word for the configured language.
     * Looks up the using class's EXAMPLE_SEMANTIC constant if defined,
     * otherwise uses this trait's DEFAULT_EXAMPLE_SEMANTIC.
     */
    protected function exampleSemantic(string $lang, string $fallback = 'optimization'): string
    {
        $map = defined(static::class . '::EXAMPLE_SEMANTIC')
            ? static::EXAMPLE_SEMANTIC
            : self::DEFAULT_EXAMPLE_SEMANTIC;

        return $map[$lang] ?? $fallback;
    }

    /**
     * Build the "Date keywords available" line listing every variant
     * recognised for the configured language. Returns null when the parser
     * vocabulary is empty so the caller can omit the label entirely.
     */
    protected function buildKeywordListLine(string $lang): ?string
    {
        $keywords = $this->searchDateParser->listKeywords();
        if (empty($keywords)) {
            return null;
        }

        $filter = $this->resolveLanguagesToShow($lang);
        $items = [];

        foreach ($keywords as $variants) {
            $picked = $this->pickVariantsForLanguages($variants, $filter);
            if (empty($picked)) {
                continue;
            }
            $items[] = implode(' / ', $picked);
        }

        return empty($items) ? null : implode(', ', $items);
    }

    /**
     * Decide which languages to expose given a language_mode setting.
     *
     *  - Specific language ('en', 'ru', ...): only that language.
     *  - 'auto' / 'multilingual' / unknown: all loaded languages.
     */
    protected function resolveLanguagesToShow(string $lang): ?array
    {
        if ($lang === 'auto' || $lang === 'multilingual') {
            return null;
        }
        if (!isset($this->supportedLanguages[$lang])) {
            return null;
        }
        return [$lang];
    }

    /**
     * Filter the variants list by language via a cheap script-based heuristic.
     * Cyrillic-only → ru, Latin-only → en. Unknown scripts pass through, since
     * hiding a keyword the user might need is worse than showing an extra one.
     *
     * @param string[]       $variants
     * @param string[]|null  $allowedLanguages  null = no filter
     * @return string[]
     */
    protected function pickVariantsForLanguages(array $variants, ?array $allowedLanguages): array
    {
        if ($allowedLanguages === null) {
            return $variants;
        }

        return array_values(array_filter($variants, function (string $v) use ($allowedLanguages) {
            $detected = $this->guessLanguage($v);
            if ($detected === null) {
                return true;
            }
            return in_array($detected, $allowedLanguages, true);
        }));
    }

    /**
     * Cheap language guess by script. Good enough for the en/ru baseline.
     * Returns null when the script doesn't match a known language.
     */
    protected function guessLanguage(string $text): ?string
    {
        if (preg_match('/[\x{0400}-\x{04FF}]/u', $text)) {
            return 'ru';
        }
        if (preg_match('/^[a-zA-Z0-9 \-\']+$/', $text)) {
            return 'en';
        }
        return null;
    }
}

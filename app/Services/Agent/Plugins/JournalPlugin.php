<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Journal\JournalServiceInterface;
use App\Contracts\Agent\Search\SearchDateParserInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginHasLanguageSettingsTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * JournalPlugin — episodic memory chronicle.
 *
 * Records structured events (actions, decisions, errors, reflections)
 * with chronological ordering AND semantic search via TF-IDF.
 *
 * This is the agent's diary: not what it knows (vectormemory / skills),
 * but what *happened* — with timestamp, type, and outcome.
 *
 * Date filter DSL (delegated to SearchDateParserInterface):
 *   - Keywords (multilingual, defined in data/search/keywords.json):
 *     today / yesterday / this_week / last_week / this_month / last_month /
 *     this_year / last_year — plus their localised variants
 *   - ISO formats: YYYY-MM-DD, YYYY-MM-DD:YYYY-MM-DD, YYYY-MM, YYYY
 *   - Combine with `|` to add a semantic query: "yesterday | optimization"
 */
class JournalPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;
    use PluginHasLanguageSettingsTrait;

    /**
     * Language-localised templates for the date-search example inserted into
     * agent instructions. Keys match `journal_language` values. We only
     * localise the *semantic part* — the date keywords themselves come from
     * the parser's vocabulary at runtime.
     *
     * For 'auto' and 'multilingual' we fall back to English semantic text
     * since we can't pick one language sensibly.
     */
    private const EXAMPLE_SEMANTIC = [
        'en' => 'optimization',
        'ru' => 'оптимизация',
        'de' => 'Optimierung',
        'fr' => 'optimisation',
        'es' => 'optimización',
    ];

    private const EXAMPLE_RANGE_SEMANTIC = [
        'en' => 'database',
        'ru' => 'база данных',
        'de' => 'Datenbank',
        'fr' => 'base de données',
        'es' => 'base de datos',
    ];

    public function __construct(
        protected JournalServiceInterface     $journalService,
        protected SearchDateParserInterface   $searchDateParser,
        protected LoggerInterface             $logger,
    ) {
    }

    public function getName(): string
    {
        return 'journal';
    }

    public function getDescription(array $config = []): string
    {
        return 'Episodic memory chronicle. Record structured events (actions, decisions, errors, reflections) with timestamps. Supports both chronological browsing and semantic search, optionally filtered by date.';
    }

    public function getInstructions(array $config = []): array
    {
        $lang = $config['journal_language'] ?? 'auto';
        $examples = $this->buildDateSearchExamples($lang);
        $keywordList = $this->buildKeywordListLine($lang);

        $instructions = [
            'Add entry:              [journal]action | Refactored memory plugin[/journal]',
            'Add with details:       [journal]error | DB failed | Timeout after 30s | outcome:failure[/journal]',
            'Add decision:           [journal]decision | Chose approach A over B | Simpler implementation[/journal]',
            'Recent entries:         [journal recent]10[/journal]',
            'Show full entry:        [journal show]42[/journal]',
            'Semantic search:        [journal search]memory optimization[/journal]',
            'Date + semantic:        ' . $examples['relative'],
            'ISO date + semantic:    ' . $examples['iso_single'],
            'Date range + semantic:  ' . $examples['iso_range'],
            'Month-level search:     ' . $examples['iso_month'],
            'Year-level search:      ' . $examples['iso_year'],
            'Date only:              ' . $examples['date_only'],
            'Delete entry:           [journal delete]42[/journal]',
            'Clear all:              [journal clear][/journal]',
        ];

        if ($keywordList !== null) {
            $instructions[] = 'Date keywords available: ' . $keywordList;
        }

        $warning = $this->buildLanguageWarning($config, 'journal_language', 'journal entries');
        if ($warning) {
            array_unshift($instructions, $warning);
        }

        return $instructions;
    }

    /**
     * Tool schema for tool_calls mode.
     *
     * Provides precise description of the pipe-separated entry format
     * and available entry types, so the model doesn't have to guess
     * the structure from implicit context.
     *
     * @return array OpenAI-compatible function descriptor (inner "function" object)
     */
    public function getToolSchema(array $config = []): array
    {
        $langInstruction = $this->buildLanguageInstruction($config, 'journal_language');
        $lang = $config['journal_language'] ?? 'auto';

        $sampleKeyword = $this->pickSampleKeyword($lang); // e.g. "yesterday" or "вчера"
        $sampleSemantic = self::EXAMPLE_SEMANTIC[$lang] ?? 'errors';

        return [
            'name'        => 'journal',
            'description' => 'Episodic memory chronicle. '
                . 'Records what happened — actions, decisions, errors, interactions — with timestamps. '
                . $langInstruction
                . 'Use for logging events, not for storing knowledge (use vectormemory for that).',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['execute', 'recent', 'search', 'show', 'delete', 'clear'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'execute (add entry): pipe-separated format:',
                            '"type | summary" or',
                            '"type | summary | details" or',
                            '"type | summary | details | outcome:success".',
                            'Types: action, decision, interaction, error, observation, reflection.',
                            'Example: "interaction | Eugeny introduced himself as viking | told me his name | outcome:success".',
                            'recent: number of entries to return (default 10).',
                            'search: query string, optionally prefixed with a date filter and "|" separator.',
                            'Date filter accepts ISO formats (YYYY-MM-DD, YYYY-MM-DD:YYYY-MM-DD, YYYY-MM, YYYY)',
                            'and keywords (today, yesterday, this/last week, this/last month, this/last year — and their localised forms).',
                            "Example: \"{$sampleKeyword} | {$sampleSemantic}\" or \"2024-03-15 | memory\".",
                            'show/delete: numeric entry ID.',
                            'clear: leave empty.',
                        ]),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Journal Plugin',
                'description' => 'Episodic memory chronicle with semantic search',
                'required'    => false,
            ],
            'journal_language' => $this->getLanguageConfigField(
                'Journal Language',
                'Force language for journal entries. Also affects which date keywords appear in agent instructions and tool examples.'
            ),
            'default_limit' => [
                'type'        => 'number',
                'label'       => 'Default entries limit',
                'description' => 'How many entries to show by default',
                'min'         => 1,
                'max'         => 50,
                'value'       => 10,
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['journal_language'])) {
            $valid = array_keys($this->supportedLanguages);
            if (!in_array($config['journal_language'], $valid, true)) {
                $errors['journal_language'] = 'Invalid language selection.';
            }
        }

        if (isset($config['default_limit'])) {
            $l = (int) $config['default_limit'];
            if ($l < 1 || $l > 50) {
                $errors['default_limit'] = 'Limit must be between 1 and 50';
            }
        }
        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return array_merge(
            [
                'enabled'       => false,
                'default_limit' => 10,
            ],
            $this->getDefaultLanguageConfig('journal_language')
        );
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    // -------------------------------------------------------------------------
    // Commands
    // -------------------------------------------------------------------------

    /**
     * Default execute — add a journal entry.
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Journal plugin is disabled.';
        }

        $result = $this->journalService->addEntry($context->preset, $content);
        return $result['message'];
    }

    /**
     * [journal recent]N[/journal]
     */
    public function recent(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Journal plugin is disabled.';
        }

        $limit = !empty(trim($content)) ? (int) trim($content) : ($context->get('default_limit', 10));
        $result = $this->journalService->recent($context->preset, $limit);
        return $result['message'];
    }

    /**
     * [journal show]ID[/journal]
     */
    public function show(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Journal plugin is disabled.';
        }

        $id = (int) trim($content);
        if ($id <= 0) {
            return 'Error: Provide a valid entry ID.';
        }

        $result = $this->journalService->show($context->preset, $id);
        return $result['message'];
    }

    /**
     * [journal search]query[/journal]
     * [journal search]2024-03-15 | query[/journal]
     * [journal search]yesterday | query[/journal]
     * [journal search]today[/journal]
     */
    public function search(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Journal plugin is disabled.';
        }

        $query = trim($content);
        if (empty($query)) {
            return 'Error: Provide a search query.';
        }

        $limit = $context->get('default_limit', 10);
        $result = $this->journalService->search($context->preset, $query, $limit);
        return $result['message'];
    }

    /**
     * [journal delete]ID[/journal]
     */
    public function delete(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Journal plugin is disabled.';
        }

        $id = (int) trim($content);
        if ($id <= 0) {
            return 'Error: Provide a valid entry ID. Use [journal delete]42[/journal]';
        }

        $result = $this->journalService->delete($context->preset, $id);
        return $result['message'];
    }

    /**
     * [journal clear][/journal]
     */
    public function clear(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Journal plugin is disabled.';
        }

        $result = $this->journalService->clear($context->preset);
        return $result['message'];
    }

    // -------------------------------------------------------------------------
    // Localised example/keyword helpers
    // -------------------------------------------------------------------------

    /**
     * Build the date-search example lines for getInstructions().
     * Keywords come from the parser vocabulary, filtered by language;
     * if the requested language has no variant for a key, English is used
     * as a fallback so the example always renders something usable.
     *
     * @return array{relative: string, iso_single: string, iso_range: string, iso_month: string, iso_year: string, date_only: string}
     */
    private function buildDateSearchExamples(string $lang): array
    {
        $yesterday  = $this->localisedKeyword('yesterday', $lang);
        $today      = $this->localisedKeyword('today', $lang);
        $lastMonth  = $this->localisedKeyword('last_month', $lang);
        $semantic   = self::EXAMPLE_SEMANTIC[$lang] ?? 'errors';
        $rangeSem   = self::EXAMPLE_RANGE_SEMANTIC[$lang] ?? 'database';

        return [
            'relative'   => "[journal search]{$yesterday} | {$semantic}[/journal]",
            'iso_single' => "[journal search]2024-03-15 | {$semantic}[/journal]",
            'iso_range'  => "[journal search]2024-03-10:2024-03-15 | {$rangeSem}[/journal]",
            'iso_month'  => "[journal search]2024-03 | {$semantic}[/journal]",
            'iso_year'   => "[journal search]2024 | {$semantic}[/journal]",
            'date_only'  => "[journal search]{$today}[/journal]   (also: {$lastMonth})",
        ];
    }

    /**
     * Build the "Date keywords available" line listing every variant the
     * parser will recognise for the configured language.
     *
     * Returns null if no keywords are loaded (parser vocabulary empty) —
     * caller skips the line in that case so we don't show a hanging label.
     */
    private function buildKeywordListLine(string $lang): ?string
    {
        $keywords = $this->searchDateParser->listKeywords();
        if (empty($keywords)) {
            return null;
        }

        $filter = $this->resolveLanguagesToShow($lang);

        $items = [];
        foreach ($keywords as $canonicalKey => $variants) {
            $picked = $this->pickVariantsForLanguages($variants, $filter);
            if (empty($picked)) {
                continue;
            }
            // Variants for the same canonical key get joined with " / " —
            // tighter visually than commas, signals "these mean the same thing".
            $items[] = implode(' / ', $picked);
        }

        return empty($items) ? null : implode(', ', $items);
    }

    /**
     * Pick a localised variant of a keyword for inline use in a single example.
     * Falls back to English, then to the canonical key itself if nothing matches.
     */
    private function localisedKeyword(string $canonicalKey, string $lang): string
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
     * One short keyword used in getToolSchema content description.
     * Prefers "yesterday"-style for brevity.
     */
    private function pickSampleKeyword(string $lang): string
    {
        return $this->localisedKeyword('yesterday', $lang);
    }

    /**
     * Decide which languages to expose given a journal_language setting.
     *
     * - Specific language ('en', 'ru', ...): only that language.
     * - 'auto' / 'multilingual' / unknown: all loaded languages.
     *
     * @return string[]|null Array of language codes to allow, or null = no filter.
     */
    private function resolveLanguagesToShow(string $lang): ?array
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
     * Filter the variants list of one keyword down to entries whose lowercased
     * form matches one of the allowed languages.
     *
     * Since the parser flattens variants without language tags (by design,
     * to enable cross-language lookup), we re-derive language by re-reading
     * the same JSON file would be wasteful. Instead we use a simple heuristic:
     * Cyrillic-only strings are Russian, Latin-only strings are English, etc.
     * For languages we don't have a heuristic for, we accept all variants.
     *
     * This is good enough for the en/ru baseline; when more languages land,
     * extend the heuristics or refactor the parser to keep per-language tags.
     *
     * @param string[] $variants
     * @param string[]|null $allowedLanguages null = no filter
     * @return string[]
     */
    private function pickVariantsForLanguages(array $variants, ?array $allowedLanguages): array
    {
        if ($allowedLanguages === null) {
            return $variants;
        }

        return array_values(array_filter($variants, function (string $v) use ($allowedLanguages) {
            $detected = $this->guessLanguage($v);
            // If we can't guess, allow it through — better to show extra
            // than to hide a keyword the user might need.
            if ($detected === null) {
                return true;
            }
            return in_array($detected, $allowedLanguages, true);
        }));
    }

    /**
     * Cheap language guess by script. Good enough for the en/ru baseline.
     */
    private function guessLanguage(string $text): ?string
    {
        if (preg_match('/[\x{0400}-\x{04FF}]/u', $text)) {
            return 'ru';
        }
        if (preg_match('/^[a-zA-Z0-9 \-\']+$/', $text)) {
            return 'en';
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // CommandPluginInterface boilerplate
    // -------------------------------------------------------------------------

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function getSelfClosingTags(): array
    {
        return ['clear', 'recent'];
    }

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        // Journal doesn't inject into context automatically —
    }
}

<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\Search\SearchDateParserInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginHasDateKeywordsTrait;
use App\Services\Agent\Plugins\Traits\PluginHasLanguageSettingsTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use Psr\Log\LoggerInterface;

/**
 * RagQueryPlugin
 *
 * Allows the agent to explicitly set one or more RAG search queries for the
 * next thinking cycle, bypassing the automatic query formulation via the RAG
 * preset model.
 *
 * When the agent uses [rag query]...[/rag], the query is appended to a
 * pending list stored in preset metadata. Multiple calls within the same cycle
 * accumulate — they do NOT overwrite each other.
 *
 * On the next cycle, RagContextEnricher checks for pending queries and uses
 * them directly (running flat + journal search for each) instead of calling
 * formulateQuery(). After use, the pending list is cleared automatically.
 *
 * If no [rag query] command was issued, RAG falls back to automatic query
 * formulation as usual — full backward compatibility.
 *
 * The query string passed to [rag query] may include the same inline filters
 * understood by VectorMemory search: "domain:", "time:", and "pulse:". The
 * RAG enricher routes the queries to the same engine, so the filters apply
 * uniformly.
 */
class RagQueryPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;
    use PluginHasLanguageSettingsTrait;
    use PluginHasDateKeywordsTrait;

    public const PLUGIN_NAME = 'rag';
    public const META_KEY    = 'pending_queries';

    /** Maximum number of queries that can be queued per cycle. */
    private const MAX_QUERIES = 5;

    /** Maximum length of a single query string. */
    private const MAX_QUERY_LENGTH = 200;

    public function __construct(
        protected LoggerInterface                        $logger,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface            $placeholderService,
        protected PluginMetadataServiceInterface         $pluginMetadata,
        protected SearchDateParserInterface              $searchDateParser,
    ) {
    }

    /** @inheritDoc */
    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    /** @inheritDoc */
    public function getDescription(array $config = []): string
    {
        return 'Queue one or more explicit RAG search queries for the next cycle. '
            . 'Multiple calls accumulate — each adds a new query angle instead of overwriting.';
    }

    /** @inheritDoc */
    public function getInstructions(array $config = []): array
    {
        $lang = $config['rag_language'] ?? 'auto';

        $yesterday = $this->localisedKeyword('yesterday', $lang);
        $lastWeek  = $this->localisedKeyword('last_week', $lang);
        $exQuery   = $this->exampleSemantic($lang);

        $pulseEnabled = !empty($config['pulse_search_enabled']);

        $instructions = [
            "Add a simple RAG query: [rag query]{$exQuery}[/rag]",
            "Add a time-bounded RAG query: [rag query]time:{$yesterday} | {$exQuery}[/rag]",
            "Search by month: [rag query]time:2026-03 | {$exQuery}[/rag]",
            "Search by year: [rag query]time:2025 | {$exQuery}[/rag]",
            "Add a domain-bounded RAG query: [rag query]domain:work | {$exQuery}[/rag]",
            "Combine time + domain: [rag query]time:{$lastWeek} | domain:work | {$exQuery}[/rag]",
        ];

        if ($pulseEnabled) {
            $instructions[] = "Add a circadian RAG query (part-of-day filter): [rag query]pulse:0-300 | {$exQuery}[/rag]";
            $instructions[] = "Night-owl pattern, range across midnight: [rag query]pulse:800-200 | {$exQuery}[/rag]";
            $instructions[] = "Combine all three filter types: [rag query]time:{$lastWeek} | domain:work | pulse:400-700 | {$exQuery}[/rag]";
        }

        $instructions[] = 'Show current pending RAG queries: [rag show][/rag]';
        $instructions[] = 'Clear all pending RAG queries: [rag clear][/rag]';

        // Date keywords reference, drawn from the parser's live vocabulary —
        // helps the agent discover what time:<expr> understands instead of guessing.
        $keywordList = $this->buildKeywordListLine($lang);
        if ($keywordList !== null) {
            $instructions[] = 'Date keywords available for time:<...>: ' . $keywordList;
            $instructions[] = 'Date ISO formats also accepted: YYYY-MM-DD, YYYY-MM-DD:YYYY-MM-DD, YYYY-MM, YYYY';
        }

        // Pulse orientation note — anchors numeric values to felt time-of-day,
        // so the model has reference points instead of guessing the scale.
        // Only shown when pulse search is enabled for this plugin.
        if ($pulseEnabled) {
            $instructions[] = 'Pulse range — circadian position in the day (0..999, where 0 is midnight, 250 ≈ morning, 500 ≈ noon, 750 ≈ evening). Range syntax: pulse:N-M. Open-ended: pulse:N- or pulse:-M. When N > M the range wraps midnight (e.g. pulse:800-200 = late evening through early morning).';
        }

        $warning = $this->buildLanguageWarning($config, 'rag_language', 'RAG queries');
        if ($warning) {
            array_unshift($instructions, $warning);
        }

        return $instructions;
    }

    public function getToolSchema(array $config = []): array
    {
        $langInstruction = $this->buildLanguageInstruction($config, 'rag_language');
        $lang            = $config['rag_language'] ?? 'auto';
        $pulseEnabled    = !empty($config['pulse_search_enabled']);

        $sampleKeyword  = $this->localisedKeyword('yesterday', $lang);
        $sampleSemantic = $this->exampleSemantic($lang);

        $filterClause = 'Optional inline filters (any order, separated by "|"): '
            . '"domain:work,relationships | ..." filters by domain, '
            . '"time:<expr> | ..." filters by absolute time';

        if ($pulseEnabled) {
            $filterClause .= ', "pulse:N-M | ..." filters by circadian position in the day '
                . '(0..999, wraps midnight when N > M)';
        }

        $filterClause .= '. Time expr accepts keywords (today, yesterday, this/last week/month/year — and localised forms) '
            . 'and ISO formats (YYYY-MM-DD, YYYY-MM-DD:YYYY-MM-DD, YYYY-MM, YYYY). ';

        if ($pulseEnabled) {
            $filterClause .= "Example: \"time:{$sampleKeyword} | {$sampleSemantic}\" or "
                . "\"pulse:0-300 | {$sampleSemantic}\" or combined: "
                . "\"time:{$sampleKeyword} | pulse:400-700 | {$sampleSemantic}\".";
        } else {
            $filterClause .= "Example: \"time:{$sampleKeyword} | {$sampleSemantic}\" or "
                . "\"domain:work | {$sampleSemantic}\".";
        }

        return [
            'name'        => 'rag',
            'description' => 'Queue one or more explicit RAG search queries for the next cycle. '
                . 'Multiple calls accumulate — each adds a new query angle instead of overwriting. '
                . $langInstruction,
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['query', 'show', 'clear'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', array_filter([
                            'query: the search query text.',
                            $langInstruction ? 'Must be written in the configured language.' : '',
                            $filterClause,
                            'show/clear: leave empty.',
                        ])),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    /** @inheritDoc */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        return "Invalid format. Use correct syntax";
    }

    /**
     * Append a pending RAG query for the next cycle.
     * Each call adds to the list — does NOT overwrite previous queries.
     */
    public function query(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: RAG query plugin is disabled.';
        }

        $query = $this->sanitizeQuery($content);

        if (empty($query)) {
            return 'Error: RAG query cannot be empty.';
        }

        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return sprintf('Error: RAG query is too long (max %d characters).', self::MAX_QUERY_LENGTH);
        }

        $queries = $this->loadQueries($context);

        if (count($queries) >= self::MAX_QUERIES) {
            return sprintf(
                'Error: RAG query limit reached (%d max per cycle). Use [rag clear][/rag] to reset.',
                self::MAX_QUERIES
            );
        }

        $queries[] = $query;
        $this->saveQueries($context, $queries);

        $this->logger->info('RagQueryPlugin: query appended', [
            'preset_id'   => $context->preset->getId(),
            'query'       => $query,
            'total_count' => count($queries),
        ]);

        $total = count($queries);
        return $total === 1
            ? "RAG query queued for next cycle: \"{$query}\""
            : "RAG query queued for next cycle: \"{$query}\" (total queued: {$total})";
    }

    /**
     * Show current pending RAG queries (if any).
     */
    public function show(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: RAG query plugin is disabled.';
        }

        $queries = $this->loadQueries($context);

        if (empty($queries)) {
            return 'No pending RAG queries. Automatic query formulation will be used on next cycle.';
        }

        $lines = ['Pending RAG queries:'];
        foreach ($queries as $i => $q) {
            $lines[] = sprintf('  %d. "%s"', $i + 1, $q);
        }

        return implode("\n", $lines);
    }

    /**
     * Clear all pending RAG queries.
     */
    public function clear(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: RAG query plugin is disabled.';
        }

        $this->pluginMetadata->remove($context->preset, self::PLUGIN_NAME, self::META_KEY);

        return 'All pending RAG queries cleared. Automatic query formulation will be used on next cycle.';
    }

    /** @inheritDoc */
    public function registerShortcodes(PluginExecutionContext $context): void
    {
        // No placeholders needed for this plugin
    }

    /** @inheritDoc */
    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    /** @inheritDoc */
    public function getCustomErrorMessage(): ?string
    {
        return 'Error: RAG query command failed.';
    }

    /** @inheritDoc */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable RAG Query Plugin',
                'description' => 'Allow agent to queue explicit RAG queries for next cycle',
                'required'    => false,
            ],
            'rag_language' => $this->getLanguageConfigField(
                'RAG Query Language',
                'Force language for RAG queries. Model will be instructed accordingly. Should match the language of your memory data.'
            ),
            'pulse_search_enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable pulse (circadian) search',
                'description' => 'Adds pulse:N-M filter syntax to RAG query instructions — lets the agent '
                    . 'query memories by position in the day (subjective time). Only useful if the agent '
                    . 'operates with pulse/subjective time. Pairs with the preset\'s "pulse_dates" setting. '
                    . 'Off by default.',
                'value'       => false,
                'required'    => false,
            ],
        ];
    }

    /** @inheritDoc */
    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['rag_language'])) {
            $valid = array_keys($this->supportedLanguages);
            if (!in_array($config['rag_language'], $valid, true)) {
                $errors['rag_language'] = 'Invalid language selection.';
            }
        }

        return $errors;
    }

    /** @inheritDoc */
    public function getDefaultConfig(): array
    {
        return array_merge(
            [
                'enabled'              => false,
                'pulse_search_enabled' => false,
            ],
            $this->getDefaultLanguageConfig('rag_language')
        );
    }

    /** @inheritDoc */
    public function canBeMerged(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function getMergeSeparator(): ?string
    {
        return null;
    }

    /** @inheritDoc */
    public function getSelfClosingTags(): array
    {
        return ['show', 'clear'];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Load the current pending query list from metadata.
     *
     * @return string[]
     */
    private function loadQueries(PluginExecutionContext $context): array
    {
        $raw = $this->pluginMetadata->get($context->preset, self::PLUGIN_NAME, self::META_KEY);

        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persist the query list to metadata as JSON.
     *
     * @param string[] $queries
     */
    private function saveQueries(PluginExecutionContext $context, array $queries): void
    {
        $this->pluginMetadata->set(
            $context->preset,
            self::PLUGIN_NAME,
            self::META_KEY,
            json_encode(array_values($queries), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Strip surrounding quotes and normalise whitespace from a raw query string.
     */
    private function sanitizeQuery(string $raw): string
    {
        $trimmed = trim($raw);
        // Remove surrounding quote characters (straight, typographic)
        $trimmed = trim($trimmed, "\"'«»\u{201C}\u{201D}\u{2018}\u{2019}");

        return preg_replace('/\s+/', ' ', $trimmed);
    }

}

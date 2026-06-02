<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\Search\SearchDateParserInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryFactoryInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryServiceInterface;
use App\Models\VectorMemory;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use Psr\Log\LoggerInterface;
use App\Services\Agent\Plugins\MemoryPlugin;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginHasDateKeywordsTrait;
use App\Services\Agent\Plugins\Traits\PluginHasLanguageSettingsTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;

/**
 * VectorMemoryPlugin class
 *
 * VectorMemoryPlugin provides semantic search capabilities using TF-IDF vectorization.
 * It allows storing and searching memories by meaning, not just exact keywords.
 * Can optionally integrate with regular memory plugin for better discoverability.
 *
 * Domain-aware routing (MCP-style dynamic methods):
 *   - [vectormemory]content[/vectormemory]                 → write to default_domain
 *   - [vectormemory work]content[/vectormemory]            → write to 'work' domain
 *   - [vectormemory search]query[/vectormemory]            → search across all domains
 *   - [vectormemory search]domain:work | query[/vectormemory] → search in specific domain(s)
 *   - [vectormemory recent|show|delete|clear|domains|purge] → known methods, see below
 *
 *   Any method name NOT in the known-methods list is treated as a domain name
 *   and the content is stored there. This mirrors how McpPlugin treats unknown
 *   methods as server keys.
 *
 * Search filters (all combinable, any order, separated by " | "):
 *   - domain:NAME[,NAME2]  — restrict to one or more domains
 *   - time:<expr>          — restrict by absolute time (keywords or ISO)
 *   - pulse:N-M            — restrict by circadian position in the day (0..999,
 *                            wraps midnight when N > M)
 */
class VectorMemoryPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;
    use PluginHasLanguageSettingsTrait;
    use PluginHasDateKeywordsTrait;

    /**
     * Methods that are NOT domain names — they have dedicated handlers.
     * Anything else passed as the method position is interpreted as a domain name.
     */
    private const KNOWN_METHODS = ['search', 'recent', 'show', 'delete', 'clear', 'domains', 'purge'];

    protected VectorMemoryServiceInterface $vectorMemoryService;

    public function __construct(
        protected LoggerInterface $logger,
        protected VectorMemoryFactoryInterface $vectorMemoryFactory,
        protected MemoryServiceInterface $memoryService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected SearchDateParserInterface $searchDateParser,
    ) {
        $this->vectorMemoryService = $this->vectorMemoryFactory->make();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'vectormemory';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(array $config = []): string
    {
        $maxEntries = $config['max_entries'] ?? 1000;
        $engine     = $config['memory_engine'] ?? 'tfidf';
        $mode       = $config['memory_mode']   ?? 'flat';
        $engineLabel = $engine === 'embedding' ? 'semantic embedding' : 'TF-IDF keyword';
        $modeLabel   = $mode   === 'associative' ? 'associative chain' : 'flat';

        return "Vector memory: {$modeLabel} search via {$engineLabel} similarity. Stores up to {$maxEntries} entries per preset. Records are organized into named domains.";
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(array $config = []): array
    {
        $lang = $config['language_mode'] ?? 'auto';
        $forceLanguage = ($lang !== 'auto' && $lang !== 'multilingual');
        $langName = $forceLanguage ? ($this->supportedLanguages[$lang] ?? strtoupper($lang)) : null;
        $defaultDomain = $this->resolveDefaultDomain($config);

        $allowClear = $config['allow_agent_clear']        ?? false;
        $allowPurge = $config['allow_agent_purge_domain'] ?? true;
        $pulseEnabled = !empty($config['pulse_search_enabled']);

        // Localised tokens for date-search examples
        $yesterday  = $this->localisedKeyword('yesterday', $lang);
        $lastWeek   = $this->localisedKeyword('last_week', $lang);
        $thisMonth  = $this->localisedKeyword('this_month', $lang);
        $exQuery    = $this->exampleSemantic($lang);

        $instructions = [
            "Store in default domain '{$defaultDomain}': [vectormemory]Successfully optimized database queries using indexes[/vectormemory]",
            'Store in a specific domain: [vectormemory work]Eugeny prefers concise responses[/vectormemory]',
            'Search across all domains: [vectormemory search]how to speed up code[/vectormemory]',
            'Search in specific domain(s): [vectormemory search]domain:work | optimization[/vectormemory]',
            'Search in multiple domains: [vectormemory search]domain:work,relationships | something[/vectormemory]',
            "Search in time window: [vectormemory search]time:{$yesterday} | {$exQuery}[/vectormemory]",
            "Search by month: [vectormemory search]time:2026-03 | {$exQuery}[/vectormemory]",
            "Search by year: [vectormemory search]time:2025 | {$exQuery}[/vectormemory]",
            "Combine time + domain: [vectormemory search]time:{$lastWeek} | domain:work | {$exQuery}[/vectormemory]",
            "Listing only by time (no semantics): [vectormemory search]time:{$thisMonth}[/vectormemory]",
        ];

        if ($pulseEnabled) {
            $instructions[] = "Filter by part of day (pulse range): [vectormemory search]pulse:0-300 | morning thoughts[/vectormemory]";
            $instructions[] = "Filter by late hours, range across midnight: [vectormemory search]pulse:800-200 | reflections[/vectormemory]";
            $instructions[] = "Combine all three filters: [vectormemory search]time:{$lastWeek} | domain:work | pulse:400-700 | {$exQuery}[/vectormemory]";
            $instructions[] = "Listing only by part of day: [vectormemory search]pulse:800-200[/vectormemory]";
        }

        $instructions = array_merge($instructions, [
            'List all domains with counts: [vectormemory domains][/vectormemory]',
            'Show recent memories: [vectormemory recent]5[/vectormemory]',
            'Show full memory item by id: [vectormemory show]42[/vectormemory]',
            'Delete by ID: [vectormemory delete]42[/vectormemory]',
            'Delete by content: [vectormemory delete]optimization query[/vectormemory]',
        ]);

        if ($allowPurge) {
            $instructions[] = 'Purge (PERMANENTLY DELETE) a domain: [vectormemory purge]domain_name[/vectormemory]';
            $instructions[] = 'Same effect, shorthand: [vectormemory clear]domain_name[/vectormemory]';
        }

        if ($allowClear) {
            $instructions[] = 'WIPE ALL memories of this preset (destructive!): [vectormemory clear][/vectormemory]';
        }

        if ($forceLanguage) {
            array_unshift($instructions, "⚠️ All vectormemory entries MUST be stored in {$langName}.");
        }

        // Date keywords reference, drawn from the parser's live vocabulary.
        // Helps the agent discover the vocabulary instead of guessing.
        $keywordList = $this->buildKeywordListLine($lang);
        if ($keywordList !== null) {
            $instructions[] = 'Date keywords available for time:<...>: ' . $keywordList;
            $instructions[] = 'Date ISO formats also accepted: YYYY-MM-DD, YYYY-MM-DD:YYYY-MM-DD, YYYY-MM, YYYY';
        }

        // Pulse orientation note — gives the model anchors for what numeric
        // values mean in felt time-of-day terms. Without these, the model
        // would have to guess the scale (0..999 vs 0..23, etc).
        // Only shown when pulse search is enabled for this plugin.
        if ($pulseEnabled) {
            $instructions[] = 'Pulse range — circadian position in the day (0..999, where 0 is midnight, 250 ≈ morning, 500 ≈ noon, 750 ≈ evening). Range syntax: pulse:N-M. Open-ended: pulse:N- or pulse:-M. When N > M the range wraps midnight (e.g. pulse:800-200 = late evening through early morning).';
        }

        $instructions[] = "Note: any method name that is NOT one of [search, recent, show, delete, clear, domains, purge] is interpreted as a domain name for storing.";

        return $instructions;
    }

    /**
     * Tool schema for tool_calls mode.
     *
     * Explicitly enumerates known methods + describes the convention that
     * any other value of `method` is treated as a target domain name.
     *
     * @return array OpenAI-compatible function descriptor (inner "function" object)
     */
    public function getToolSchema(array $config = []): array
    {
        $engine = $config['memory_engine'] ?? 'tfidf';
        $mode   = $config['memory_mode']   ?? 'flat';
        $lang   = $config['language_mode'] ?? 'auto';

        $engineLabel = $engine === 'embedding' ? 'semantic embedding' : 'TF-IDF keyword';
        $modeLabel   = $mode   === 'associative' ? 'associative chain' : 'flat top-K';

        $forceLanguage = ($lang !== 'auto' && $lang !== 'multilingual');
        $langName = $forceLanguage ? ($this->supportedLanguages[$lang] ?? strtoupper($lang)) : null;
        $langInstruction = $forceLanguage ? " ALL memories MUST be stored in {$langName}. " : '';

        $defaultDomain = $this->resolveDefaultDomain($config);
        $allowClear    = $config['allow_agent_clear']        ?? false;
        $allowPurge    = $config['allow_agent_purge_domain'] ?? true;
        $pulseEnabled  = !empty($config['pulse_search_enabled']);

        $description = 'Semantic memory: store crystallized knowledge and retrieve it by meaning. '
            . "Uses {$engineLabel} similarity with {$modeLabel} retrieval. "
            . "Records are organized into named domains; default is '{$defaultDomain}'. "
            . 'Store insights, confirmed facts, patterns, events. '
            . $langInstruction
            . 'Different from journal (which records what happened) — '
            . 'vectormemory stores what you know.';

        // Build the list of available known methods based on gates
        $methods = ['execute', 'search', 'recent', 'show', 'delete', 'domains'];
        if ($allowPurge) {
            $methods[] = 'purge';
        }
        if ($allowClear || $allowPurge) {
            // clear is exposed if either gate is open: full wipe (allowClear)
            // or domain-purge shorthand (allowPurge)
            $methods[] = 'clear';
        }

        $methodDescription = implode(' ', [
            'Operation. Known methods: ' . implode(', ', $methods) . '.',
            'ANY OTHER value is treated as a target DOMAIN NAME for storing the content into.',
            "Example: method='work' with content='...' stores the content in domain 'work'.",
        ]);

        $searchClause = 'search: a natural language query. Optional inline filters (any order, separated by "|"): '
            . '"domain:work,relationships | ..." filters by domain, '
            . '"time:yesterday | ..." or "time:2026-03 | ..." or "time:2025 | ..." filters by absolute time range';

        if ($pulseEnabled) {
            $searchClause .= ', "pulse:N-M | ..." filters by circadian position in the day '
                . '(0..999, where 0 is midnight, 250 ≈ morning, 500 ≈ noon, 750 ≈ evening). '
                . 'Pulse range wraps midnight when N > M (e.g. pulse:800-200 covers late evening into early morning). '
                . 'Can combine all three: "time:last week | domain:work | pulse:400-700 | optimization"';
        } else {
            $searchClause .= '. Can combine: "time:last week | domain:work | optimization"';
        }

        $searchClause .= '. Empty query with only filters returns chronological listing.';

        $contentParts = [
            'Argument depends on method.',
            "execute (STORE in default domain '{$defaultDomain}'): the text to remember.",
            $forceLanguage ? "MUST be in {$langName}." : null,
            'Example: "Eugeny prefers concise responses".',
            "STORE in a specific domain: set method to the domain name (e.g. method='work') and content to the text.",
            $searchClause,
            'recent: number of entries to return, e.g. "5" (default 5).',
            'show/delete: numeric memory ID (delete also accepts a content fragment).',
            'domains: leave empty.',
        ];

        if ($allowPurge) {
            $contentParts[] = 'purge: domain name to PERMANENTLY DELETE. Cannot purge the default domain.';
        }
        if ($allowClear || $allowPurge) {
            if ($allowClear && $allowPurge) {
                $contentParts[] = 'clear: empty content wipes ALL memories of this preset; non-empty content is treated as a domain name to purge (same as purge method).';
            } elseif ($allowClear) {
                $contentParts[] = 'clear: leave empty to wipe ALL memories of this preset.';
            } else {
                $contentParts[] = 'clear: provide a domain name to purge that domain (same as purge method). Empty content is not allowed unless full clear is enabled.';
            }
        }

        return [
            'name'        => 'vectormemory',
            'description' => $description,
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => $methodDescription,
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', array_filter($contentParts)),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getCustomSuccessMessage(): ?string
    {
        return "Vector memory operation completed successfully.";
    }

    /**
     * @inheritDoc
     */
    public function getCustomErrorMessage(): ?string
    {
        return "Error: Vector memory operation failed. Check the syntax and try again.";
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Vector Memory Plugin',
                'description' => 'Allow semantic memory storage and search',
                'required' => false
            ],

            'memory_mode' => [
                'type'        => 'select',
                'label'       => 'Search mode',
                'description' => 'Flat returns the top-K most similar memories directly. Associative traverses related memories via chain walk for richer recall.',
                'options'     => [
                    'flat'        => 'Flat — direct top-K search',
                    'associative' => 'Associative — chain traversal through related memories',
                ],
                'value'    => 'flat',
                'required' => false,
            ],
            'memory_engine' => [
                'type'        => 'select',
                'label'       => 'Similarity engine',
                'description' => 'TF-IDF uses keyword overlap (always available). Embedding uses semantic similarity — requires an embedding capability configured for this preset.',
                'options'     => [
                    'tfidf'     => 'TF-IDF — keyword similarity (no API required)',
                    'embedding' => 'Embedding — semantic similarity (requires embedding capability)',
                ],
                'value'    => 'tfidf',
                'required' => false,
            ],
            'default_domain' => [
                'type'        => 'text',
                'label'       => 'Default domain',
                'description' => 'Domain assigned to memories stored without an explicit domain name. Falls back to "global" if empty.',
                'value'       => VectorMemory::DEFAULT_DOMAIN,
                'required'    => false,
            ],
            'cross_domain_bridges' => [
                'type'        => 'checkbox',
                'label'       => 'Cross-Domain Bridges',
                'description' => 'Allow associative search to follow semantic bridges into other domains. Top results from the target domain act as anchors — if strong semantic connections to other domains exist, those are included as supplementary results.',
                'value'       => false,
                'required'    => false,
            ],
            'pulse_search_enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable pulse (circadian) search',
                'description' => 'Adds pulse:N-M filter syntax to search instructions — lets the agent query '
                    . 'memories by position in the day (subjective time). Only useful if the agent operates '
                    . 'with pulse/subjective time. Pairs with the preset\'s "pulse_dates" setting. Off by default.',
                'value'       => false,
                'required'    => false,
            ],
            'allow_agent_clear' => [
                'type'        => 'checkbox',
                'label'       => 'Allow agent to clear ALL memories',
                'description' => 'Permit the agent to wipe ALL vector memories of this preset via [vectormemory clear][/vectormemory]. Off by default — this is the most destructive action available.',
                'value'       => false,
                'required'    => false,
            ],
            'allow_agent_purge_domain' => [
                'type'        => 'checkbox',
                'label'       => 'Allow agent to purge a domain',
                'description' => 'Permit the agent to PERMANENTLY DELETE all records of a single domain via [vectormemory purge]name[/vectormemory] OR the equivalent [vectormemory clear]name[/vectormemory] shorthand. The default domain is always protected. On by default — less destructive than full clear.',
                'value'       => true,
                'required'    => false,
            ],
            'max_entries' => [
                'type' => 'number',
                'label' => 'Max Memory Entries',
                'description' => 'Maximum number of vector memories to store',
                'min' => 100,
                'max' => 5000,
                'value' => 1000,
                'required' => false
            ],
            'similarity_threshold' => [
                'type' => 'number',
                'label' => 'Similarity Threshold',
                'description' => 'Minimum similarity score (0.0-1.0) for search results',
                'min' => 0.0,
                'max' => 1.0,
                'step' => 0.01,
                'value' => 0.1,
                'required' => false
            ],
            'search_limit' => [
                'type' => 'number',
                'label' => 'Search Results Limit',
                'description' => 'Maximum number of search results to return',
                'min' => 1,
                'max' => 20,
                'value' => 5,
                'required' => false
            ],
            'auto_cleanup' => [
                'type' => 'checkbox',
                'label' => 'Auto Cleanup Old Entries',
                'description' => 'Automatically remove oldest entries when limit is reached',
                'value' => true,
                'required' => false
            ],
            'boost_recent' => [
                'type' => 'checkbox',
                'label' => 'Boost Recent Memories',
                'description' => 'Give higher relevance to more recent memories',
                'value' => true,
                'required' => false
            ],
            'integrate_with_memory' => [
                'type' => 'checkbox',
                'label' => 'Integrate with Memory Plugin',
                'description' => 'Add reference links to regular memory when storing vector memories',
                'value' => false,
                'required' => false
            ],
            'memory_link_format' => [
                'type' => 'select',
                'label' => 'Memory Link Format',
                'description' => 'How to format memory links in regular memory',
                'options' => [
                    'short' => 'Short: "Vector: keyword1, keyword2"',
                    'descriptive' => 'Descriptive: "Vector memory about: brief description"',
                    'timestamped' => 'Timestamped: "[MM-DD HH:mm] Vector: keywords"'
                ],
                'value' => 'descriptive',
                'required' => false
            ],
            'max_link_keywords' => [
                'type' => 'number',
                'label' => 'Max Keywords in Link',
                'description' => 'Maximum number of keywords to show in memory link',
                'min' => 2,
                'max' => 10,
                'value' => 4,
                'required' => false
            ],
            'language_mode' => [
                'type' => 'select',
                'label' => 'Language Processing',
                'description' => 'How to handle different languages',
                'options' => $this->supportedLanguages,
                'value' => 'auto',
                'required' => false
            ],
            'display_content_length' => [
                'type' => 'number',
                'label' => 'Display Content Length',
                'description' => 'Maximum number of characters to show in search results',
                'min' => 100,
                'max' => 1000,
                'value' => 500,
                'required' => false
            ],
            'custom_stop_words_ru' => [
                'type' => 'textarea',
                'label' => 'Custom Russian Stop Words',
                'description' => 'Additional Russian stop words (comma-separated)',
                'placeholder' => 'слово1, слово2, слово3',
                'required' => false
            ],
            'custom_stop_words_en' => [
                'type' => 'textarea',
                'label' => 'Custom English Stop Words',
                'description' => 'Additional English stop words (comma-separated)',
                'placeholder' => 'word1, word2, word3',
                'required' => false
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['memory_mode']) && !in_array($config['memory_mode'], ['flat', 'associative'])) {
            $errors['memory_mode'] = 'Memory mode must be flat or associative.';
        }

        if (isset($config['memory_engine']) && !in_array($config['memory_engine'], ['tfidf', 'embedding'])) {
            $errors['memory_engine'] = 'Memory engine must be tfidf or embedding.';
        }

        if (isset($config['max_entries'])) {
            $maxEntries = (int) $config['max_entries'];
            if ($maxEntries < 100 || $maxEntries > 5000) {
                $errors['max_entries'] = 'Max entries must be between 100 and 5000';
            }
        }

        if (isset($config['similarity_threshold'])) {
            $threshold = (float) $config['similarity_threshold'];
            if ($threshold < 0.0 || $threshold > 1.0) {
                $errors['similarity_threshold'] = 'Similarity threshold must be between 0.0 and 1.0';
            }
        }

        if (isset($config['search_limit'])) {
            $limit = (int) $config['search_limit'];
            if ($limit < 1 || $limit > 20) {
                $errors['search_limit'] = 'Search limit must be between 1 and 20';
            }
        }

        if (isset($config['max_link_keywords'])) {
            $keywords = (int) $config['max_link_keywords'];
            if ($keywords < 2 || $keywords > 10) {
                $errors['max_link_keywords'] = 'Max keywords in link must be between 2 and 10';
            }
        }

        if (isset($config['display_content_length'])) {
            $length = (int) $config['display_content_length'];
            if ($length < 100 || $length > 1000) {
                $errors['display_content_length'] = 'Display content length must be between 100 and 1000';
            }
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'memory_mode'              => VectorMemoryFactoryInterface::MODE_FLAT,
            'memory_engine'            => VectorMemoryFactoryInterface::ENGINE_TFIDF,
            'default_domain'           => VectorMemory::DEFAULT_DOMAIN,
            'cross_domain_bridges' => false,
            'allow_agent_clear'        => false,
            'allow_agent_purge_domain' => true,
            'pulse_search_enabled'     => false,
            'max_entries' => 1000,
            'similarity_threshold' => 0.1,
            'search_limit' => 5,
            'auto_cleanup' => true,
            'boost_recent' => true,
            'integrate_with_memory' => false,
            'memory_link_format' => 'descriptive',
            'max_link_keywords' => 4,
            'language_mode' => 'auto',
            'display_content_length' => 500,
            'custom_stop_words_ru' => '',
            'custom_stop_words_en' => ''
        ];
    }

    // -------------------------------------------------------------------------
    // Dynamic method routing (MCP-style)
    // -------------------------------------------------------------------------

    /**
     * Any non-empty method name is valid: known methods go to their handlers,
     * everything else is treated as a domain name for storing.
     */
    public function hasMethod(string $method): bool
    {
        if (in_array($method, self::KNOWN_METHODS, true)) {
            return true;
        }

        // Unknown method = domain name for storing
        return $method !== '' && $method !== 'execute';
    }

    public function callMethod(string $method, string $content, PluginExecutionContext $context): string
    {
        if (in_array($method, self::KNOWN_METHODS, true) && method_exists($this, $method)) {
            return $this->{$method}($content, $context);
        }

        // Treat $method as a target domain name for storing
        return $this->storeToDomain($method, $content, $context);
    }

    // -------------------------------------------------------------------------
    // Storage operations
    // -------------------------------------------------------------------------

    /**
     * Default execute — store in the default domain.
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Vector memory plugin is disabled.";
        }

        return $this->storeToDomain(null, $content, $context);
    }

    /**
     * Unified store path. Null domain = use default from config.
     */
    private function storeToDomain(?string $domain, string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Vector memory plugin is disabled.";
        }

        try {
            $config = $context->config;
            if ($domain !== null) {
                $config['domain'] = $domain;
            }

            $result = $this->vectorMemoryService->storeVectorMemory($context->preset, $content, $config);

            if (!$result['success']) {
                return $result['message'];
            }

            $message = $result['message'];

            // Add to regular memory if integration is enabled
            if ($context->config['integrate_with_memory'] ?? false) {
                $memoryLinkResult = $this->addToRegularMemory($result['memory'], $context);
                if ($memoryLinkResult) {
                    $message .= " " . $memoryLinkResult;
                }
            }

            return $message;

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::storeToDomain error: " . $e->getMessage());
            return "Error storing content: " . $e->getMessage();
        }
    }

    /**
     * Search memories by semantic similarity, optionally with time/domain/pulse filters.
     */
    public function search(string $query, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Vector memory plugin is disabled.";
        }

        try {
            $result = $this->vectorMemoryService->searchVectorMemories(
                $context->preset,
                $query,
                $context->config
            );

            if (!$result['success']) {
                return $result['message'];
            }

            $temporal = !empty($result['temporal']);

            if (empty($result['results'])) {
                return $result['message'] ?? "No memories found for query: '{$query}'.";
            }

            $headerParts = [];
            if (!empty($result['domains'])) {
                $headerParts[] = 'in [' . implode(', ', $result['domains']) . ']';
            }
            if (!empty($result['from']) && !empty($result['to'])) {
                $from = $result['from'];
                $to   = $result['to'];
                $headerParts[] = $from->isSameDay($to)
                    ? 'on ' . $from->toDateString()
                    : 'between ' . $from->toDateString() . ' and ' . $to->toDateString();
            } elseif (!empty($result['from'])) {
                $headerParts[] = 'from ' . $result['from']->toDateString();
            } elseif (!empty($result['to'])) {
                $headerParts[] = 'until ' . $result['to']->toDateString();
            }
            // Pulse range note — only shown when filter was active.
            // null check uses isset() because 0 is a legitimate pulse bound.
            $pf = $result['pulseFrom'] ?? null;
            $pt = $result['pulseTo']   ?? null;
            if ($pf !== null || $pt !== null) {
                if ($pf !== null && $pt !== null) {
                    $cross = ($pf > $pt) ? ' (across midnight)' : '';
                    $headerParts[] = "pulse {$pf}-{$pt}{$cross}";
                } elseif ($pf !== null) {
                    $headerParts[] = "from pulse {$pf}";
                } else {
                    $headerParts[] = "up to pulse {$pt}";
                }
            }
            $headerNote = !empty($headerParts) ? ' (' . implode(', ', $headerParts) . ')' : '';

            $countWord = $temporal ? 'memories in window' : 'similar memories';
            $output = "Found " . count($result['results']) . " {$countWord}{$headerNote}:\n\n";

            foreach ($result['results'] as $searchResult) {
                $memory = $searchResult['document'] ?? $searchResult['memory'];
                $date = $memory->getCreatedAt()->format('M j, H:i');
                $truncateLength = $context->config['display_content_length'] ?? 500;
                $content = $this->truncateContent($memory->getTextContent(), $truncateLength);
                $id = $memory->id;
                $domain = $memory->domain ?? VectorMemory::DEFAULT_DOMAIN;

                if ($temporal) {
                    // No similarity score makes sense for chronological listing
                    $output .= "• [ID:{$id}, domain:{$domain}, {$date}] {$content}\n";
                } else {
                    $similarity = round(($searchResult['similarity'] ?? 0) * 100, 1);
                    $source = $searchResult['source'] ?? '';
                    $bridgeNote = ($source === 'cross_domain_bridge')
                        ? " (bridge from another domain)"
                        : '';
                    $output .= "• [ID:{$id}, domain:{$domain}, {$similarity}% match{$bridgeNote}, {$date}] {$content}\n";
                }
            }

            return $output;

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::search error: " . $e->getMessage());
            return "Error searching memories: " . $e->getMessage();
        }
    }

    /**
     * Show recent memories.
     */
    public function recent(string $limitStr, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Vector memory plugin is disabled.";
        }

        try {
            $limit = $limitStr !== '' ? max(1, min((int) $limitStr, 20)) : 5;
            $result = $this->vectorMemoryService->getRecentVectorMemories($context->preset, $limit);

            if (!$result['success']) {
                return $result['message'];
            }

            $memories = $result['memories'];

            if ($memories->isEmpty()) {
                return "No memories stored yet.";
            }

            $output = "Recent {$memories->count()} memories:\n\n";

            foreach ($memories as $memory) {
                $date = $memory->created_at->format('M j, H:i');
                $truncateLength = $context->config['display_content_length'] ?? 500;
                $content = $this->truncateContent($memory->content, $truncateLength);
                $features = count($memory->tfidf_vector);
                $domain = $memory->domain ?? VectorMemory::DEFAULT_DOMAIN;

                $output .= "• [ID:{$memory->id}, domain:{$domain}, {$date}, {$features} features] {$content}\n";
            }

            return $output;

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::recent error: " . $e->getMessage());
            return "Error retrieving recent memories: " . $e->getMessage();
        }
    }

    /**
     * Show full memory by ID.
     */
    public function show(string $memoryId, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Vector memory plugin is disabled.";
        }

        try {
            $id = (int) $memoryId;
            $memory = $this->vectorMemoryService->getVectorMemoryById($context->preset, $id);

            if (!$memory) {
                return "Memory with ID {$id} not found.";
            }

            $date = $memory->created_at->format('M j, H:i');
            $features = count($memory->tfidf_vector);
            $keywords = implode(', ', $memory->keywords ?? []);
            $domain = $memory->domain ?? VectorMemory::DEFAULT_DOMAIN;

            return "Memory ID {$id} [domain:{$domain}, {$date}, {$features} features]:\n\n" .
                "{$memory->content}\n\n" .
                "Keywords: {$keywords}";

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::show error: " . $e->getMessage());
            return "Error showing memory: " . $e->getMessage();
        }
    }

    /**
     * Clear vector memories.
     *
     * Two modes:
     *   - Empty content: wipe ALL memories of this preset. Gated by `allow_agent_clear`.
     *   - Non-empty content: treat content as a domain name and purge that domain.
     *     Equivalent to [vectormemory purge]name[/vectormemory]. Gated by `allow_agent_purge_domain`.
     *
     * The "argument-as-purge" shorthand exists because passing an argument to
     * `clear` is a clear signal of intent — there's no other reason to do it.
     */
    public function clear(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Vector memory plugin is disabled.";
        }

        $content = trim($content);

        // Mode 2: clear with argument = purge a domain
        if ($content !== '') {
            return $this->purgeDomainOperation($content, $context);
        }

        // Mode 1: clear without argument = wipe whole preset
        if (!($context->config['allow_agent_clear'] ?? false)) {
            return "Error: Full memory clear is not enabled for this preset. "
                . "Ask the user to enable 'allow_agent_clear' in the plugin config. "
                . "To remove a specific domain, use [vectormemory purge]domain_name[/vectormemory] "
                . "or [vectormemory clear]domain_name[/vectormemory] instead (if domain purge is enabled).";
        }

        try {
            $result = $this->vectorMemoryService->clearVectorMemories($context->preset);
            return $result['message'];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::clear error: " . $e->getMessage());
            return "Error clearing memories: " . $e->getMessage();
        }
    }

    /**
     * Delete specific vector memory by ID or content search.
     */
    public function delete(string $identifier, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Vector memory plugin is disabled.";
        }

        try {
            $identifier = trim($identifier);

            if (empty($identifier)) {
                return "Error: Please provide memory ID or content to search for deletion.";
            }

            // Try to parse as ID first
            if (is_numeric($identifier)) {
                $id = (int) $identifier;
                if ($id > 0) {
                    $result = $this->vectorMemoryService->deleteVectorMemory($context->preset, $id);
                    return $result['message'];
                }
            }

            // If not a valid ID, search by content (across all domains intentionally —
            // ID-based delete remains the precise tool; content-based is a convenience)
            $searchResult = $this->vectorMemoryService->searchVectorMemories($context->preset, $identifier, [
                'search_limit' => 1,
                'similarity_threshold' => 0.3
            ]);

            if (!$searchResult['success'] || empty($searchResult['results'])) {
                return "No memory found matching '{$identifier}'. Try using exact ID or different search terms.";
            }

            $memory = $searchResult['results'][0]['memory'];
            $similarity = round($searchResult['results'][0]['similarity'] * 100, 1);

            $deleteResult = $this->vectorMemoryService->deleteVectorMemory($context->preset, $memory->id);

            if ($deleteResult['success']) {
                $preview = $this->truncateContent($memory->content, 60);
                $domain = $memory->domain ?? VectorMemory::DEFAULT_DOMAIN;
                return "Deleted memory (ID:{$memory->id}, domain:{$domain}, {$similarity}% match): {$preview}";
            }

            return $deleteResult['message'];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::delete error: " . $e->getMessage());
            return "Error deleting memory: " . $e->getMessage();
        }
    }

    // -------------------------------------------------------------------------
    // Domain operations
    // -------------------------------------------------------------------------

    /**
     * List all domains used by this preset with record counts.
     * Idempotent and harmless — no flag required.
     */
    public function domains(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Vector memory plugin is disabled.";
        }

        try {
            $domains = $this->vectorMemoryService->listDomains($context->preset);

            if (empty($domains)) {
                return "No memory domains yet. Store a memory to create one.";
            }

            $defaultDomain = $this->resolveDefaultDomain($context->config);
            $lines = ['Available memory domains:'];

            foreach ($domains as $d) {
                $marker = $d['name'] === $defaultDomain ? ' (default)' : '';
                $lines[] = "  • {$d['name']} — {$d['count']} record(s){$marker}";
            }

            return implode("\n", $lines);

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::domains error: " . $e->getMessage());
            return "Error listing domains: " . $e->getMessage();
        }
    }

    /**
     * Permanently delete all records of a domain.
     * Gated behind `allow_agent_purge_domain`. Default domain is always protected.
     *
     * Equivalent to [vectormemory clear]name[/vectormemory] — both routes converge
     * on purgeDomainOperation().
     */
    public function purge(string $domainName, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Vector memory plugin is disabled.";
        }

        return $this->purgeDomainOperation($domainName, $context);
    }

    /**
     * Shared core for domain purge. Called from both `purge` and `clear` (with argument).
     * Enforces gate, default-domain protection, normalisation, and emptiness.
     */
    private function purgeDomainOperation(string $domainName, PluginExecutionContext $context): string
    {
        if (!($context->config['allow_agent_purge_domain'] ?? true)) {
            return "Error: Domain purge is not enabled for this preset. "
                . "Ask the user to enable 'allow_agent_purge_domain' in the plugin config.";
        }

        $domainName = mb_strtolower(trim($domainName));
        if ($domainName === '') {
            return "Error: Provide a domain name to purge.";
        }

        $defaultDomain = $this->resolveDefaultDomain($context->config);
        if ($domainName === $defaultDomain) {
            return "Error: The default domain '{$defaultDomain}' cannot be purged. "
                . "It is the home of any memory that hasn't been assigned elsewhere. "
                . "If you really need to wipe everything including the default domain, "
                . "use [vectormemory clear][/vectormemory] without arguments (if enabled).";
        }

        try {
            $deleted = $this->vectorMemoryService->purgeDomain($context->preset, $domainName);

            if ($deleted === 0) {
                return "Domain '{$domainName}' had no records — nothing to purge.";
            }

            return "Purged domain '{$domainName}': {$deleted} record(s) permanently deleted.";

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::purgeDomainOperation error: " . $e->getMessage());
            return "Error purging domain: " . $e->getMessage();
        }
    }

    // -------------------------------------------------------------------------
    // Misc
    // -------------------------------------------------------------------------

    /**
     * Resolve the default domain name from config (with fallback).
     */
    private function resolveDefaultDomain(array $config): string
    {
        $raw = trim((string) ($config['default_domain'] ?? ''));
        return $raw === '' ? VectorMemory::DEFAULT_DOMAIN : $raw;
    }

    /**
     * Get vector memory service instance for external use
     *
     * @return VectorMemoryServiceInterface
     */
    public function getVectorMemoryService(): VectorMemoryServiceInterface
    {
        return $this->vectorMemoryService;
    }

    /**
     * Add reference to regular memory plugin
     *
     * @param \App\Models\VectorMemory $vectorMemory
     * @param PluginExecutionContext $context
     * @return string|null
     */
    private function addToRegularMemory($vectorMemory, PluginExecutionContext $context): ?string
    {
        try {
            // Get memory plugin instance
            $memoryPlugin = $this->getMemoryPlugin();

            if (!$memoryPlugin) {
                $this->logger->warning("Memory plugin not found. Cannot add vector memory reference.");
                return null;
            }

            $format = $context->get('memory_link_format', 'descriptive');
            $maxKeywords = $context->get('max_link_keywords', 4);

            // Limit keywords
            $limitedKeywords = array_slice($vectorMemory->keywords ?? [], 0, $maxKeywords);

            $link = $this->formatMemoryLink($vectorMemory, $limitedKeywords, $format);

            // Add to memory using the memory service
            $result = $this->memoryService->addMemoryItem($context->preset, $link);

            return "Added reference to regular memory.";

        } catch (\Throwable $e) {
            $this->logger->warning("Failed to add vector memory reference to regular memory: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get memory plugin instance
     *
     * @return MemoryPlugin|null
     */
    private function getMemoryPlugin(): ?MemoryPlugin
    {
        /* @var MemoryPlugin $memoryPlugin */
        return app(PluginRegistryInterface::class)->get('memory');
    }

    /**
     * Format memory link based on selected format
     *
     * @param \App\Models\VectorMemory $vectorMemory
     * @param array $keywords
     * @param string $format
     * @return string
     */
    private function formatMemoryLink($vectorMemory, array $keywords, string $format): string
    {
        $keywordsStr = implode(', ', $keywords);
        $shortContent = $this->truncateContent($vectorMemory->content, 50);

        return match($format) {
            'short' => "Vector: {$keywordsStr}",
            'timestamped' => "[{$vectorMemory->created_at->format('m-d H:i')}] Vector: {$keywordsStr}",
            'descriptive' => "Vector memory about: {$shortContent} (search: [vectormemory search]{$keywordsStr}[/vectormemory])",
            default => "Vector: {$keywordsStr}"
        };
    }

    /**
     * Truncate content for display with proper UTF-8 support and word boundaries
     *
     * @param string $content Content to truncate
     * @param int $length Maximum length in characters (not bytes)
     * @param bool $respectWordBoundaries Whether to avoid cutting words in the middle
     * @return string Truncated content
     */
    private function truncateContent(string $content, int $length, bool $respectWordBoundaries = true): string
    {
        // Use mb_strlen for proper UTF-8 character counting
        if (mb_strlen($content, 'UTF-8') <= $length) {
            return $content;
        }

        // Truncate using mb_substr for proper UTF-8 handling
        $truncated = mb_substr($content, 0, $length, 'UTF-8');

        if ($respectWordBoundaries) {
            // Find the last space to avoid cutting words in the middle
            $lastSpace = mb_strrpos($truncated, ' ', 0, 'UTF-8');

            // If we found a space and it's not too close to the beginning
            if ($lastSpace !== false && $lastSpace > ($length * 0.7)) {
                $truncated = mb_substr($truncated, 0, $lastSpace, 'UTF-8');
            }
        }

        return $truncated . '...';
    }

    /**
     * @inheritDoc
     */
    public function getMergeSeparator(): ?string
    {
        return "\n";
    }

    /**
     * @inheritDoc
     */
    public function canBeMerged(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     *
     * Registers a [[vector_memory_domains]] placeholder that expands into the
     * live domain registry for this preset. Also (re)builds the underlying
     * VectorMemoryService instance to match current mode/engine config.
     */
    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $mode   = $context->get('memory_mode', VectorMemoryFactoryInterface::MODE_FLAT);
        $engine = $context->get('memory_engine', VectorMemoryFactoryInterface::ENGINE_TFIDF);

        $this->vectorMemoryService = $this->vectorMemoryFactory->make($mode, $engine);

        $presetId = $context->preset->getId();
        $scope    = $this->shortcodeScopeResolver->preset($presetId);

        $this->placeholderService->registerDynamic(
            'vector_memory_domains',
            'Live list of vector memory domains for this preset (name + record count)',
            fn () => $this->renderDomainsPlaceholder($context),
            $scope
        );
    }

    /**
     * Render the domains list for the [[vector_memory_domains]] placeholder.
     */
    private function renderDomainsPlaceholder(PluginExecutionContext $context): string
    {
        try {
            $domains = $this->vectorMemoryService->listDomains($context->preset);

            if (empty($domains)) {
                return "(no domains yet — store a memory to create one)";
            }

            $defaultDomain = $this->resolveDefaultDomain($context->config);
            $lines = [];

            foreach ($domains as $d) {
                $marker = $d['name'] === $defaultDomain ? ' (default)' : '';
                $lines[] = "  {$d['name']} ({$d['count']}){$marker}";
            }

            return implode("\n", $lines);

        } catch (\Throwable $e) {
            $this->logger->warning("VectorMemoryPlugin: failed to render domains placeholder: " . $e->getMessage());
            return "(domain list unavailable)";
        }
    }

    /**
     * @inheritDoc
     */
    public function getSelfClosingTags(): array
    {
        return ['clear', 'domains'];
    }

    public function allowsCrossPresetExecution(): bool
    {
        return true;
    }

}

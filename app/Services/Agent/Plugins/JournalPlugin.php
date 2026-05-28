<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Journal\JournalServiceInterface;
use App\Contracts\Agent\Search\SearchDateParserInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginHasDateKeywordsTrait;
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
 * Commands:
 *   [journal]type | summary[/journal]                        — add entry
 *   [journal]type | summary | details[/journal]              — add with details
 *   [journal]type | summary | details | outcome:success[/journal] — full entry
 *   [journal recent]10[/journal]                             — last N entries
 *   [journal show]42[/journal]                               — full entry details
 *   [journal search]query[/journal]                          — semantic search
 *   [journal search]2024-03-15 | query[/journal]             — date + semantic
 *   [journal search]yesterday | query[/journal]              — relative date
 *   [journal search]2024-03-10:2024-03-15 | query[/journal]  — date range
 *   [journal search]today[/journal]                          — date only
 *   [journal search]pulse:0-300 | query[/journal]            — circadian + semantic (if enabled)
 *   [journal delete]42[/journal]                             — delete entry
 *   [journal clear][/journal]                                — clear all
 */
class JournalPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;
    use PluginHasLanguageSettingsTrait;
    use PluginHasDateKeywordsTrait;

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
        $pulseEnabled = !empty($config['pulse_search_enabled']);

        $yesterday = $this->localisedKeyword('yesterday', $lang);
        $today     = $this->localisedKeyword('today', $lang);
        $exQuery   = $this->exampleSemantic($lang);

        $instructions = [
            'Add entry:              [journal]action | Refactored memory plugin[/journal]',
            'Add with details:       [journal]error | DB failed | Timeout after 30s | outcome:failure[/journal]',
            'Add decision:           [journal]decision | Chose approach A over B | Simpler implementation[/journal]',
            'Recent entries:         [journal recent]10[/journal]',
            'Show full entry:        [journal show]42[/journal]',
            "Semantic search:        [journal search]{$exQuery}[/journal]",
            "Date + semantic:        [journal search]2024-03-15 | {$exQuery}[/journal]",
            "Relative date:          [journal search]{$yesterday} | {$exQuery}[/journal]",
            "Date range + semantic:  [journal search]2024-03-10:2024-03-15 | {$exQuery}[/journal]",
            "Month-level search:     [journal search]2024-03 | {$exQuery}[/journal]",
            "Year-level search:      [journal search]2024 | {$exQuery}[/journal]",
            "Date only:              [journal search]{$today}[/journal]",
        ];

        if ($pulseEnabled) {
            $instructions[] = "Circadian search:       [journal search]pulse:0-300 | {$exQuery}[/journal]";
            $instructions[] = "Night-owl (across midnight): [journal search]pulse:800-200 | {$exQuery}[/journal]";
            $instructions[] = "Pulse + date combined:  [journal search]pulse:0-300 | {$yesterday} | {$exQuery}[/journal]";
            $instructions[] = "Pulse-only listing:     [journal search]pulse:0-300[/journal]";
        }

        $instructions[] = 'Delete entry:           [journal delete]42[/journal]';
        $instructions[] = 'Clear all:              [journal clear][/journal]';

        // Date keywords reference — helps the agent discover what date expressions work
        $keywordList = $this->buildKeywordListLine($lang);
        if ($keywordList !== null) {
            $instructions[] = 'Date keywords available: ' . $keywordList;
            $instructions[] = 'Date ISO formats also accepted: YYYY-MM-DD, YYYY-MM-DD:YYYY-MM-DD, YYYY-MM, YYYY';
        }

        // Pulse orientation note — only when circadian search is enabled.
        if ($pulseEnabled) {
            $instructions[] = 'Pulse range — circadian position in the day (0..999, where 0 is midnight, 250 ≈ morning, 500 ≈ noon, 750 ≈ evening). Range syntax: pulse:N-M. Open-ended: pulse:N- or pulse:-M. When N > M the range wraps midnight (e.g. pulse:800-200 = late evening through early morning). Combine freely with date filters.';
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
        $lang            = $config['journal_language'] ?? 'auto';
        $pulseEnabled    = !empty($config['pulse_search_enabled']);

        $sampleKeyword  = $this->localisedKeyword('yesterday', $lang);
        $sampleSemantic = $this->exampleSemantic($lang);

        // Build the search-argument description, conditionally including pulse.
        $searchDesc = 'search: query string, optionally prefixed with a date filter and "|" separator. '
            . 'Date filter accepts ISO formats (YYYY-MM-DD, YYYY-MM-DD:YYYY-MM-DD, YYYY-MM, YYYY) '
            . 'and keywords (today, yesterday, this/last week/month/year — and their localised forms). ';

        if ($pulseEnabled) {
            $searchDesc .= 'Also accepts a "pulse:N-M | ..." prefix that filters by circadian position '
                . 'in the day (0..999, wraps midnight when N > M); combine freely with the date filter. '
                . "Example: \"{$sampleKeyword} | {$sampleSemantic}\", \"pulse:0-300 | {$sampleSemantic}\", "
                . "or \"2024-03-15 | memory\".";
        } else {
            $searchDesc .= "Example: \"{$sampleKeyword} | {$sampleSemantic}\" or \"2024-03-15 | memory\".";
        }

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
                            'Types: action, decision, interaction, error, observation, event.',
                            'Example: "interaction | Eugeny introduced himself as viking | told me his name | outcome:success".',
                            'recent: number of entries to return (default 10).',
                            $searchDesc,
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
                'Force language for journal entries.'
            ),
            'pulse_search_enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable pulse (circadian) search',
                'description' => 'Adds pulse:N-M filter syntax to journal search instructions — lets the agent '
                    . 'query entries by position in the day (subjective time). Only useful if the agent '
                    . 'operates with pulse/subjective time. Pairs with the preset\'s "pulse_dates" setting. '
                    . 'Off by default.',
                'value'       => false,
                'required'    => false,
            ],
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
                'enabled'              => false,
                'pulse_search_enabled' => false,
                'default_limit'        => 10,
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
     * [journal search]pulse:0-300 | query[/journal]
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

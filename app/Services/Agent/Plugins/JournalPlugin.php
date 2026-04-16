<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Journal\JournalServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
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
 *   [journal delete]42[/journal]                             — delete entry
 *   [journal clear][/journal]                                — clear all
 */
class JournalPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected JournalServiceInterface $journalService,
        protected LoggerInterface         $logger,
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
        return [
            'Add entry:              [journal]action | Refactored memory plugin[/journal]',
            'Add with details:       [journal]error | DB failed | Timeout after 30s | outcome:failure[/journal]',
            'Add decision:           [journal]decision | Chose approach A over B | Simpler implementation[/journal]',
            'Recent entries:         [journal recent]10[/journal]',
            'Show full entry:        [journal show]42[/journal]',
            'Semantic search:        [journal search]memory optimization[/journal]',
            'Date + semantic:        [journal search]2024-03-15 | memory optimization[/journal]',
            'Relative date:          [journal search]yesterday | errors[/journal]',
            'Date range + semantic:  [journal search]2024-03-10:2024-03-15 | database[/journal]',
            'Date only:              [journal search]today[/journal]',
            'Delete entry:           [journal delete]42[/journal]',
            'Clear all:              [journal clear][/journal]',
        ];
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
        return [
            'name'        => 'journal',
            'description' => 'Episodic memory chronicle. '
                . 'Records what happened — actions, decisions, errors, interactions — with timestamps. '
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
                            'search: query string, optionally prefixed with date: "yesterday | errors" or "2024-03-15 | memory".',
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
        return [
            'enabled'       => true,
            'default_limit' => 10,
        ];
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

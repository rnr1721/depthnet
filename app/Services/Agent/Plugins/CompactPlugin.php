<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;

/**
 * CompactPlugin — agent-initiated memory consolidation.
 *
 * The agent calls this on a logical boundary (a topic closed, a task finished,
 * the conversation's centre of gravity moved) to fold the current window into a
 * first-person recap and clear the working context — the "sleep / consolidate"
 * cycle. It does NOT compact inline: like Reflect, it sets a one-shot flag that
 * the agent handler consumes at the START of the next cycle, before the context
 * is assembled, so the fold happens against a settled window and the recap is
 * in place before the builder runs.
 *
 * Usage (tag mode):
 *   [compact][/compact]                 — consolidate now, profile from context mode
 *   [compact]task-state[/compact]       — consolidate, bias toward task-state
 *   [compact]salience[/compact]         — consolidate, bias toward what mattered
 *
 * Usage (tool_calls mode):
 *   compact(content: "")                — consolidate now
 *   compact(content: "task-state")      — with a focus hint
 *
 * The focus string is forwarded to the compressor prompt AND selects the
 * journal entry type for this one pass (task/state → observation, salience/
 * reflect → reflection); empty leaves the mode-derived default.
 *
 * Watchdog compaction (forced when the window grows past the preset's limit)
 * runs the same CompactionService directly and does not touch this plugin —
 * this plugin is only the agent-driven trigger.
 */
class CompactPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'compact';

    /** Plugin-metadata namespace/keys for the one-shot handoff to the cycle. */
    public const META_PENDING = 'pending';
    public const META_FOCUS   = 'focus';

    public function __construct(
        protected PluginMetadataServiceInterface $pluginMetadataService,
    ) {
    }

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(array $config = []): string
    {
        return 'Consolidate the current conversation into memory and clear the working window. '
            . 'Use on a logical boundary — a topic closed, a task done — to keep a continuous sense '
            . 'of self without carrying the whole raw context. Your summary is kept; details remain '
            . 'reachable from memory.';
    }

    public function getInstructions(array $config = []): array
    {
        return [
            'Consolidate now: [compact][/compact]',
            'With a focus on task state: [compact]task-state[/compact]',
            'With a focus on what mattered: [compact]salience[/compact]',
            'Takes effect at the start of the next cycle: the window is folded into a first-person '
                . 'recap and cleared, and your recap returns as the opening of the fresh window. '
                . 'Nothing is lost — the folded messages stay in memory (journal + associative recall).',
        ];
    }

    public function getToolSchema(array $config = []): array
    {
        return [
            'name'        => self::PLUGIN_NAME,
            'description' => 'Consolidate the current conversation into memory and clear the working '
                . 'window, keeping a first-person recap. Use on a logical boundary (topic closed, task '
                . 'done). Takes effect at the start of the next cycle. Nothing is lost — folded messages '
                . 'remain reachable via journal and associative memory.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'content' => [
                        'type'        => 'string',
                        'description' => 'Optional focus hint: "task-state" to preserve active '
                            . 'constraints/decisions/IDs, "salience" to preserve affect and what was '
                            . 'left unresolved. Leave empty to follow the current context mode.',
                    ],
                ],
                'required'   => [],
            ],
        ];
    }

    /**
     * Default execute — arm the one-shot compaction flag for the next cycle.
     * The optional content is the focus/profile hint.
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Compact plugin is disabled.';
        }

        if (!$context->preset->hasCompaction()) {
            return 'Compaction is not configured for this preset (no compressor preset set). '
                . 'Ask the operator to assign one, or continue without compacting.';
        }

        $this->pluginMetadataService->set(
            $context->preset,
            self::PLUGIN_NAME,
            self::META_PENDING,
            true
        );

        $focus = trim($content);
        if ($focus !== '') {
            $this->pluginMetadataService->set(
                $context->preset,
                self::PLUGIN_NAME,
                self::META_FOCUS,
                $focus
            );
            return "Consolidation scheduled for the next cycle (focus: {$focus}).";
        }

        return 'Consolidation scheduled for the next cycle.';
    }

    // ── Config ────────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Compact Plugin',
                'description' => 'Allow the agent to consolidate its own context on demand. '
                    . 'Requires a compressor preset configured on the preset.',
                'required'    => false,
            ],
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled' => false,
        ];
    }

    // ── Boilerplate ───────────────────────────────────────────────────────────

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function getSelfClosingTags(): array
    {
        // [compact][/compact] with no body is the common case.
        return ['execute'];
    }
}

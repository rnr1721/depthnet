<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginHasLanguageSettingsTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;

/**
 * MyselfPlugin — inner voice channel for autonomous agents.
 *
 * A deliberate no-op: the agent writes anything into this command —
 * reasoning, doubts, questions, observations — and the content stays
 * in the conversation context, visible in the next cycle.
 *
 * This is not communication outward (that's agent_speak).
 * This is not storage (nothing is persisted).
 * This is a trace — a thought left for oneself.
 *
 * Usage:
 *   [myself]I'm not sure whether the user is asking about X or Y.
 *   I'll proceed with X but stay ready to correct.[/myself]
 *
 * In tool_calls mode the agent calls this function with any content —
 * the result is always empty, but the call itself remains in the
 * conversation history and is visible in subsequent cycles.
 *
 * Suggested instruction in preset prompt:
 *   You have a private channel for your own thoughts. Use it freely —
 *   for reasoning, uncertainty, self-questions, plans. Nobody reads it
 *   in real time. It is simply there for you in the next cycle.
 *
 * For plugin developers:
 *   This plugin is intentionally minimal and serves as a reference
 *   implementation of a "sink" plugin — one that accepts input,
 *   does nothing with it, and returns an empty string.
 *   It demonstrates:
 *     - getToolSchema() for tool_calls mode
 *     - configurable success message via execute() → property → getCustomSuccessMessage()
 *     - PluginHasLanguageSettingsTrait usage
 *     - canBeMerged() = true for consecutive calls
 *     - no DI dependencies, no storage, no side effects
 *
 */
class MyselfPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;
    use PluginHasLanguageSettingsTrait;

    public const PLUGIN_NAME = 'myself';

    /**
     * Resolved from config during execute() so getCustomSuccessMessage()
     * can return a per-preset value without receiving a context argument.
     */
    private ?string $resolvedSuccessMessage = null;

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(array $config = []): string
    {
        return 'Inner voice channel. Write anything — reasoning, doubts, questions to yourself. '
            . 'Content stays in context for the next cycle. No side effects.';
    }

    public function getInstructions(array $config = []): array
    {
        $instructions = [
            'Write your inner reasoning, questions, or observations:',
            '  [myself]I need to think about this more carefully before acting.[/myself]',
            '',
            'This channel is yours. The content is not sent anywhere and produces no result.',
            'It stays in context — you will see it in the next cycle.',
            'Use it for: reasoning under uncertainty, self-questions, plans, reflections.',
            'Do not use it as a substitute for agent_speak — this is not communication outward.',
        ];

        $warning = $this->buildLanguageWarning($config, 'myself_language', 'Myself queries');
        if ($warning) {
            array_unshift($instructions, $warning);
        }

        return $instructions;
    }

    /**
     * Tool schema for tool_calls mode.
     *
     * @param array $config
     * @return array OpenAI-compatible function descriptor
     */
    public function getToolSchema(array $config = []): array
    {

        $langInstruction = $this->buildLanguageInstruction($config, 'myself_language');

        return [
            'name'        => 'myself',
            'description' => 'Your private inner voice channel. Write reasoning, doubts, self-questions, '
                . 'or observations. The content stays in your context for the next cycle — '
                . 'no output is produced, no data is stored. '
                . 'Use this when you want to think out loud without addressing anyone.'
                . $langInstruction,
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'content' => [
                        'type'        => 'string',
                        'description' => 'Your inner thought, reasoning, or question to yourself. '
                            . 'Any length, no constraints.'
                            . $langInstruction,
                    ],
                ],
                'required'   => ['content'],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Myself Plugin',
                'description' => 'Allow the agent to use its inner voice channel',
                'required'    => false,
            ],
            'myself_language' => $this->getLanguageConfigField(
                'Myself Query Language',
                'Force language for Myself queries. Model will be instructed accordingly. Should match the language of your memory data.'
            ),
            'success_message' => [
                'type'        => 'text',
                'label'       => 'Success message',
                'description' => 'Shown in system output after each inner thought. Leave empty for a silent dot.',
                'placeholder' => 'мышление выполнено',
                'required'    => false,
            ],
        ];
    }

    /** @inheritDoc */
    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['myself_language'])) {
            $valid = array_keys($this->supportedLanguages);
            if (!in_array($config['myself_language'], $valid, true)) {
                $errors['myself_language'] = 'Invalid language selection.';
            }
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return array_merge(
            [
                'enabled' => false,
                'success_message' => ''
            ],
            $this->getDefaultLanguageConfig('myself_language')
        );
    }

    // -------------------------------------------------------------------------
    // Execution — intentional no-op
    // -------------------------------------------------------------------------

    /**
     * Accept inner thought, return nothing.
     * Resolves success_message from config so getCustomSuccessMessage() can
     * return a per-preset value without receiving a context argument.
     *
     * @param string $content
     * @param PluginExecutionContext $context
     * @return string
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        return trim($context->get('success_message', ''));
    }

    // -------------------------------------------------------------------------
    // CommandPluginInterface boilerplate
    // -------------------------------------------------------------------------

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    public function getMergeSeparator(): ?string
    {
        return "\n";
    }

    public function canBeMerged(): bool
    {
        // Multiple consecutive thoughts can be merged into one context entry
        return true;
    }

    public function getSelfClosingTags(): array
    {
        return [];
    }
}

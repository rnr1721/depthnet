<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginHasLanguageSettingsTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;

/**
 * SelfNotePlugin — write a note to yourself for the next thinking cycle.
 *
 * The agent places a message into the input pool under the source name
 * configured in the plugin settings (default: "self_note"). On the next
 * cycle, the pool is assembled into the user message JSON payload, so the
 * note arrives as an authoritative external source — not as a fading
 * reasoning trace, but as a directive the model treats with the same
 * weight as user input.
 *
 * This asymmetry is the point: models tend to treat user-role content
 * more seriously than their own previous assistant-role output. A note
 * written by the agent to itself, arriving via the pool, crosses that
 * boundary deliberately.
 *
 * Usage:
 *   [memo]Next cycle: follow up on the database question. User is waiting.[/memo]
 *   [memo]Remember: I promised to check the file tomorrow.[/memo]
 *
 * Only meaningful in pool input mode or with system messages.
 *
 * Multiple [memo] calls within one cycle accumulate — each overwrites the
 * previous note for the same source_name. To preserve all notes, set a
 * unique source_name per note (not currently supported via tag syntax, but
 * possible via config if needed).
 */
class SelfNotePlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;
    use PluginHasLanguageSettingsTrait;

    public const PLUGIN_NAME = 'memo';

    public function __construct(
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected InputPoolServiceInterface $inputPoolService,
        protected PluginMetadataServiceInterface $metadataService
    ) {
    }

    // ── Identity ──────────────────────────────────────────────────────────────

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(array $config = []): string
    {
        $info = '';
        if (($config['message_mode'] ?? 'none') === 'user_message') {
            $info = 'The note is placed into the input pool and arrives as an '
            . 'authoritative directive on the next cycle — not as a fading thought, '
            . 'but as a message you sent to your future self.';
        }
        if (($config['message_mode'] ?? 'none') === 'system_message') {
            $info = 'The note is placed into the system prompt and arrives as an '
                . 'authoritative directive on the next cycle — not as a fading thought, '
                . 'but as a directive you sent to your future self.';
        }
        return 'Write a note to yourself for the next thinking cycle. ' . $info;
    }

    public function getInstructions(array $config = []): array
    {
        $instructions = [
            'Leave a note for your next thinking cycle:',
            '  [memo]Next cycle: return to the question about X[/memo]',
            '  [memo]Remember: promised to check the file tomorrow[/memo]',
            'Only one note is kept at a time — writing a new one replaces the previous.'
        ];
        if (($config['message_mode'] ?? 'none') === 'user_message') {
            $extra = [
                'The note arrives in your next cycle as a user-role message.',
                'Use it for: deferred plans, reminders, continuity between cycles.',
            ];

            array_splice($instructions, count($instructions) - 1, 0, $extra);
        }

        if (($config['message_mode'] ?? 'none') === 'system_message') {
            $extra = [
                'The note arrives in your next cycle as a system-role message.',
                'Use it for: personal instructions, reminders, continuity between cycles.',
            ];

            array_splice($instructions, count($instructions) - 1, 0, $extra);
        }

        $hint = $config['user_hint'] ?? '';
        if (!empty(trim($hint))) {
            $instructions[] = $hint;
        }

        $warning = $this->buildLanguageWarning($config, 'memo_language', 'memo notes');
        if ($warning) {
            array_unshift($instructions, $warning);
        }

        return $instructions;
    }

    public function getToolSchema(array $config = []): array
    {
        $langInstruction = $this->buildLanguageInstruction($config, 'memo_language');

        $info = '';
        if (($config['message_mode'] ?? 'none') === 'user_message') {
            $info = 'The note is placed into the input pool and arrives as a directive '
                . 'on the next cycle — treated with the same weight as user input. ';
        }

        if (($config['message_mode'] ?? 'none') === 'system_message') {
            $info = 'The note is placed into system prompt as a directive '
                . 'on the next cycle — it will be personal instructions. ';
        }

        $hint = $config['user_hint'] ?? '';
        $hintText = '';
        if (!empty(trim($hint))) {
            $hintText = "{$hint} ";
        }

        return [
            'name'        => 'memo',
            'description' => 'Leave a note for your next thinking cycle. '
                . $info
                . 'Use for deferred plans, reminders, continuity between cycles. '
                . 'Only one note is kept — writing a new one replaces the previous. '
                . $hintText
                . $langInstruction,
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation: write',
                        'enum'        => ['write'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => 'Note content for write operation. '
                            . $langInstruction,
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    // ── Execution ─────────────────────────────────────────────────────────────

    /**
     * Default execute — write a note (no method specified in tag).
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        return $this->write($content, $context);
    }

    /**
     * Write a note for the next cycle.
     */
    public function write(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: SelfNote plugin is disabled.';
        }

        $content = trim($content);

        if (empty($content)) {
            return 'Error: memo content cannot be empty.';
        }

        if ($context->get('message_mode', 'none') === 'none') {
            return 'Note applied for next cycle.';
        }

        if ($context->get('message_mode', 'none') === 'system_message') {
            // In system message mode, we add the note directly to the system prompt via placeholder.
            // The actual insertion is handled in the agent's prompt assembly logic, where it checks for this placeholder.
            $this->metadataService->set($context->preset, self::PLUGIN_NAME, 'self_system_note', $content);
            return 'Note applied for next cycle.';
        }

        // For user_message mode, we add the note to the input pool under the specified source name.
        if (!$this->inputPoolService->isEnabled($context->preset)) {
            return 'this tool works only in pool mode';
        }

        $sourceName = $context->get('source_name', 'self_note');

        if (empty(trim($sourceName)) || $sourceName === 'self_note') {
            $sourceName = $context->preset->getName();
        }

        $this->inputPoolService->add(
            $context->preset->getId(),
            $sourceName,
            $content
        );

        return 'Note applied for next cycle.';
    }

    // ── Config ────────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable SelfNote Plugin',
                'description' => 'Allow the agent to leave notes for its next thinking cycle',
                'required'    => false,
            ],
            'memo_language' => $this->getLanguageConfigField(
                'Memo Language',
                'Force language for memo notes. Should match the language your agent thinks in.'
            ),
            'message_mode' => [
                'type' => 'select',
                'label' => 'Message Mode',
                'description' => 'How to handle messages',
                'options' => [
                    'none' => 'No action',
                    'user_message' => 'User message in pool mode',
                    'system_message' => 'System message via placeholder.'
                ],
                'value' => 'none',
                'required' => false
            ],
            'source_name' => [
                'type'        => 'text',
                'label'       => 'Source name',
                'description' => 'Pool source name for self-notes. Appears as this label in the input pool JSON.',
                'placeholder' => 'self_note',
                'required'    => false,
            ],
            'user_hint' => [
                'type'        => 'text',
                'label'       => 'Custom hint for LLM',
                'description' => 'Custom instruction for the agent about when and how to use memo. Appears in plugin instructions and tool description.',
                'placeholder' => 'Use memo to remind yourself about important deadlines.',
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (!empty($config['source_name'])) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $config['source_name'])) {
                $errors['source_name'] = 'Source name must be lowercase letters, numbers and underscores only.';
            }
        }

        if (isset($config['memo_language'])) {
            $valid = array_keys($this->supportedLanguages);
            if (!in_array($config['memo_language'], $valid, true)) {
                $errors['memo_language'] = 'Invalid language selection.';
            }
        }

        if (isset($config['message_mode']) && !in_array($config['message_mode'], ['none', 'user_message', 'system_message'], true)) {
            $errors['message_mode'] = 'Invalid message mode selection.';
        }

        if (isset($config['user_hint']) && strlen($config['user_hint']) > 500) {
            $errors['user_hint'] = 'Hint must be under 500 characters.';
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return array_merge(
            [
                'enabled'     => false,
                'source_name' => '',
                'message_mode' => 'none',
                'user_hint' => '',
            ],
            $this->getDefaultLanguageConfig('memo_language')
        );
    }

    // ── Boilerplate ───────────────────────────────────────────────────────────

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return 'Error: memo command failed.';
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
        return [];
    }

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());
        $this->placeholderService->registerDynamic(
            'memo',
            'SelfNotePlugin — write a note to yourself for the next thinking cycle.',
            fn () => $this->metadataService->get($context->preset, self::PLUGIN_NAME, 'self_system_note', ''),
            $scope
        );
    }
}

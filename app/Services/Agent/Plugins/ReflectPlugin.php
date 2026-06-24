<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;

/**
 * ReflectPlugin — request an extra reasoning pass before the next response.
 *
 * When invoked, the agent flags that the upcoming cycle should run a pre-pass:
 * one additional generation over the full current context, before the speaking
 * pass. The pre-pass output is exposed to the speaking pass via [[reasoning]].
 *
 * This is the on-demand counterpart to the preset's always-on pre_pass_enabled
 * flag. Enabling THIS plugin (without the always-on box) means: the agent itself
 * decides when an extra reasoning pass is worthwhile, cycle by cycle.
 *
 * Timing: the flag set this cycle is consumed at the start of the NEXT cycle,
 * because the plugin can only be invoked from within a response, while the
 * pre-pass runs before the response. So "reflect now" means "deliberate at the
 * top of the next breath, then speak."
 *
 * The character of that reasoning — analytical, exploratory, deliberative — is
 * defined entirely by the preset's pre_pass_instruction, not by this plugin.
 * The plugin is a neutral trigger.
 */
class ReflectPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'reflect';

    /** Metadata keys consumed by Agent::shouldRunPrePass()/runPrePass(). */
    public const META_PENDING = 'dive_pending';
    public const META_FOCUS   = 'dive_focus';

    public function __construct(
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
        $base = 'Request an extra reasoning pass before your next response. ';

        if ($this->focusAllowed($config)) {
            return $base . 'You may optionally note what to focus that reasoning on.';
        }

        return $base . 'Takes no arguments — just invoke it when you want to think before answering.';
    }

    public function getInstructions(array $config = []): array
    {
        if ($this->focusAllowed($config)) {
            $instructions = [
                'Request an extra reasoning pass before your next response:',
                '  [reflect][/reflect]                              — think, no particular focus',
                '  [reflect]whether this approach scales[/reflect]  — think, focused on this',
                'The reasoning happens at the start of your next cycle, before you respond.',
            ];
        } else {
            $instructions = [
                'Request an extra reasoning pass before your next response:',
                '  [reflect][/reflect]',
                'The reasoning happens at the start of your next cycle, before you respond.',
            ];
        }

        $hint = $config['user_hint'] ?? '';
        if (!empty(trim($hint))) {
            $instructions[] = $hint;
        }

        return $instructions;
    }

    public function getToolSchema(array $config = []): array
    {
        $hint     = $config['user_hint'] ?? '';
        $hintText = !empty(trim($hint)) ? "{$hint} " : '';

        $properties = [
            'method' => [
                'type'        => 'string',
                'description' => 'Operation: execute',
                'enum'        => ['execute'],
            ],
        ];

        $description = 'Request an extra reasoning pass before your next response. '
            . 'The extra pass runs at the start of your next cycle, over your full '
            . 'current context, and its output is available to you before you respond. '
            . $hintText;

        if ($this->focusAllowed($config)) {
            $properties['content'] = [
                'type'        => 'string',
                'description' => 'Optional: what to focus the reasoning on. Leave empty for open reflection.',
            ];
            $description .= 'You may optionally pass what to focus on. ';
        }

        return [
            'name'        => self::PLUGIN_NAME,
            'description' => $description,
            'parameters'  => [
                'type'       => 'object',
                'properties' => $properties,
                'required'   => ['method'],
            ],
        ];
    }

    // ── Execution ─────────────────────────────────────────────────────────────

    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: reflect plugin is disabled.';
        }

        // Flag the next cycle to run a pre-pass. Consumed read-once by the Agent.
        $this->metadataService->set(
            $context->preset,
            self::PLUGIN_NAME,
            self::META_PENDING,
            true
        );

        // Optional focus — only honoured when the operator allowed it. Even if the
        // model sends content with the box off, we ignore it: keeps the trigger
        // clean and predictable.
        $content = trim($content);
        if ($this->focusAllowed($context->config ?? []) && $content !== '') {
            $this->metadataService->set(
                $context->preset,
                self::PLUGIN_NAME,
                self::META_FOCUS,
                $content
            );
            return 'Reasoning pass queued for next cycle, focused on your note.';
        }

        return 'Reasoning pass queued for next cycle.';
    }

    // ── Config ────────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Reflect Plugin',
                'description' => 'Let the agent request an extra reasoning pass on demand. '
                    . 'Requires a pre-pass instruction configured on the preset.',
                'required'    => false,
            ],
            'allow_focus' => [
                'type'        => 'checkbox',
                'label'       => 'Allow focus note',
                'description' => 'Let the agent optionally pass what to focus the reasoning on. '
                    . 'When off, the tool is a plain trigger with no arguments.',
                'required'    => false,
            ],
            'user_hint' => [
                'type'        => 'text',
                'label'       => 'Custom hint for LLM',
                'description' => 'Custom instruction about when to use reflect. '
                    . 'Appears in plugin instructions and tool description.',
                'placeholder' => 'Reflect before answering anything consequential.',
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['user_hint']) && strlen($config['user_hint']) > 500) {
            $errors['user_hint'] = 'Hint must be under 500 characters.';
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'     => false,
            'allow_focus' => false,
            'user_hint'   => '',
        ];
    }

    // ── Boilerplate ───────────────────────────────────────────────────────────

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return 'Error: reflect command failed.';
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
        // Allow [reflect][/reflect] with no content — invoking it IS the signal.
        return ['execute'];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function focusAllowed(array $config): bool
    {
        return (bool) ($config['allow_focus'] ?? false);
    }
}

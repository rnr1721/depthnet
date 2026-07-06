<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginHandoffTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;

/**
 * SpeakPlugin — stateless.
 *
 * Universal outbound communication channel for the agent.
 * Replaces the speak/handoff methods previously in AgentPlugin.
 *
 * Usage (tag mode):
 *   [speak]Message to interlocutor[/speak]
 *   [speak preset_code]Message to another agent[/speak]
 *
 * Usage (tool_calls mode):
 *   speak(content: "Message")                          → to interlocutor
 *   speak(content: "preset_code:Message to agent")     → handoff with message
 *
 * The execute() method is the sole entry point — target is determined
 * by the method argument:
 *   - absent / "execute" / configured interlocutor label → speak to user
 *   - any other value                                    → handoff to preset
 *
 * Mental model: "speak" is always an action, not a response terminator.
 * The agent can speak and act in the same cycle.
 */
class SpeakPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;
    use PluginHandoffTrait;

    public function __construct(
        protected PresetServiceInterface $presetService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
    ) {
    }

    public function getName(): string
    {
        return 'speak';
    }

    public function getDescription(array $config = []): string
    {
        $label = $config['interlocutor_label'] ?? 'interlocutor';
        return "Send a message to {$label} or delegate to another agent. Speaking is an action — use it freely within any cycle.";
    }

    public function getInstructions(array $config = []): array
    {
        $instructions = [];

        $label = $config['interlocutor_label'] ?? 'interlocutor';

        $instructions[] = "Send message to {$label}: [speak]Your message here[/speak]";
        $instructions[] = "Note: you can speak and act (use other tools) in the same cycle.";

        if ($config['allow_handoff'] ?? true) {
            $instructions[] = 'Delegate to another agent: [speak preset_code]Your message or task[/speak]';
            $instructions[] = 'Delegate without message: [speak preset_code][/speak]';
        }

        if (!empty($config['custom_instructions_prompt'])) {
            $instructions[] = $config['custom_instructions_prompt'];
        }

        return $instructions;
    }

    public function getToolSchema(array $config = []): array
    {
        $label = $config['interlocutor_label'] ?? 'interlocutor';
        $allowHandoff = $config['allow_handoff'] ?? true;

        $descParts = [
            "Send a message to {$label} or delegate to another agent.",
            "Speaking is an action — use freely within any cycle alongside other tools.",
        ];

        if ($allowHandoff) {
            $descParts[] = 'To delegate: pass "preset_code" or "preset_code:message" as content.';
        }

        if (!empty($config['custom_instructions_schema'])) {
            $descParts[] = $config['custom_instructions_schema'];
        }

        $contentDesc = "Message to send to {$label}.";
        if ($allowHandoff) {
            $contentDesc .= ' Or "preset_code" / "preset_code:message" to delegate to another agent.';
        }

        return [
            'name'        => 'speak',
            'description' => implode(' ', $descParts),
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'content' => [
                        'type'        => 'string',
                        'description' => $contentDesc,
                    ],
                ],
                'required'   => ['content'],
            ],
        ];
    }

    /**
     * Default execute — speak to interlocutor.
     * This is what [speak]message[/speak] calls.
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Speak plugin is disabled.';
        }

        // tool_calls mode sends "preset_code:message" for handoff
        if ($context->get('allow_handoff', true) && str_contains($content, ':')) {
            [$possibleCode, $message] = explode(':', $content, 2);
            $possibleCode = trim($possibleCode);

            // Check if it looks like a preset code (no spaces) and actually exists
            if (!str_contains($possibleCode, ' ') && $this->presetService->findByCode($possibleCode) && $possibleCode !== $context->preset->getPresetCode()) {
                return $this->dispatchToPreset($possibleCode, trim($message), $context);
            }
        }

        $this->setPluginExecutionMeta('speak', $content);
        return 'Message delivered to interlocutor.';
    }

    /**
     * Overrides PluginMethodTrait routing.
     *
     * [speak preset_code]message[/speak] arrives here as method=preset_code.
     * We treat any unrecognised method as a preset code → handoff.
     */
    public function hasMethod(string $method): bool
    {
        // All methods are valid: either it's 'execute' or it's a preset code.
        return true;
    }

    public function callMethod(string $method, string $content, PluginExecutionContext $context): string
    {
        if ($method === 'execute') {
            return $this->execute($content, $context);
        }

        // Treat $method as preset_code → handoff
        return $this->dispatchToPreset($method, $content, $context);
    }

    // ── Config ────────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Speak Plugin',
                'description' => 'Allow agent to send messages and delegate to other agents',
                'required'    => false,
            ],
            'interlocutor_label' => [
                'type'        => 'text',
                'label'       => 'Interlocutor Label',
                'description' => 'How the default output channel is referred to in instructions (e.g. "user", "operator", "Eugeny")',
                'value'       => 'interlocutor',
                'required'    => false,
            ],
            'allow_handoff' => [
                'type'        => 'checkbox',
                'label'       => 'Allow Handoff',
                'description' => 'Allow agent to delegate tasks to other presets via [speak preset_code]',
                'value'       => true,
                'required'    => false,
            ],
            'custom_instructions_prompt' => [
                'type'        => 'textarea',
                'label'       => 'Additional Instructions (system prompt)',
                'description' => 'Extra notes appended to speak instructions in the system prompt. Leave empty to skip.',
                'value'       => '',
                'required'    => false,
            ],
            'custom_instructions_schema' => [
                'type'        => 'textarea',
                'label'       => 'Additional Instructions (tool schema)',
                'description' => 'Extra notes appended to the tool schema description in tool_calls mode. Leave empty to skip.',
                'value'       => '',
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'                    => true,
            'interlocutor_label'         => 'interlocutor',
            'allow_handoff'              => true,
            'custom_instructions_prompt' => '',
            'custom_instructions_schema' => '',
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
        return [];
    }

    /**
     * Registers {{speak_targets}} shortcode — injected into the system prompt
     * to show the agent who it can speak to.
     *
     * Example output:
     *
     *   [SPEAK_TARGETS]
     *   [speak]message[/speak]              → interlocutor (default output channel)
     *   [speak vasya_ai]message[/speak]     → Vasya — RAG specialist
     *   [speak vova_predator]message[/speak]→ Vova — data analysis
     *   [/SPEAK_TARGETS]
     */
    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());

        $this->placeholderService->registerDynamic(
            'speak_targets',
            'Available speak targets (interlocutor + handoff-enabled presets)',
            function () use ($context) {
                return $this->buildSpeakTargetsBlock($context);
            },
            $scope
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Handoff to a preset. Called when method = preset_code.
     */
    private function dispatchToPreset(string $presetCode, string $message, PluginExecutionContext $context): string
    {
        if (!$context->get('allow_handoff', true)) {
            return 'Error: Handoff is not allowed in current configuration.';
        }

        if (empty(trim($presetCode))) {
            return 'Error: Empty preset code.';
        }

        return $this->dispatchHandoff($presetCode, $message ?: null, $context);
    }

    private function buildSpeakTargetsBlock(PluginExecutionContext $context): string
    {
        $label = $context->get('interlocutor_label', 'interlocutor');
        $allowHandoff = $context->get('allow_handoff', true);
        $isToolCalls = $context->preset->getAgentResultMode() === 'tool_calls';

        $lines = ['[SPEAK_TARGETS]'];
        $lines[] = $this->formatSpeakHint(null, $label . ' (default output channel)', $isToolCalls);

        if ($allowHandoff) {
            $presets = $this->presetService->getHandoffTargets($context->preset);

            foreach ($presets as $preset) {
                $code = $preset->getPresetCode();
                if (empty($code)) {
                    continue;
                }
                $desc = $preset->getDescription() ?? $preset->getName();
                $lines[] = $this->formatSpeakHint($code, $desc, $isToolCalls);
            }
        }

        $lines[] = '[/SPEAK_TARGETS]';

        return implode("\n", $lines);
    }

    /**
     * Format a single speak hint line depending on agent result mode.
     *
     * @param string|null $presetCode  null = default (interlocutor)
     * @param string      $description Human-readable target description
     * @param bool        $isToolCalls
     */
    private function formatSpeakHint(?string $presetCode, string $description, bool $isToolCalls): string
    {
        if ($isToolCalls) {
            $content = $presetCode ? "{$presetCode}:message" : "message";
            return "speak(content: \"{$content}\")  →  {$description}";
        }

        $tag = $presetCode ? "[speak {$presetCode}]" : "[speak]";
        return "{$tag}message[/speak]  →  {$description}";
    }

}

<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;
use Illuminate\Contracts\Container\Container;

/**
 * AgentPlugin — stateless.
 *
 * Allows the AI agent to control its own thinking cycles and communicate
 * with the user. All preset-specific config arrives via PluginExecutionContext.
 */
class AgentPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    /** @var AgentJobServiceInterface|null Lazy-loaded service */
    private ?AgentJobServiceInterface $agentJobService = null;

    private const OWN_METHODS = ['speak', 'resume', 'pause', 'status', 'handoff'];

    public function __construct(
        protected Container $container,
        protected LoggerInterface $logger,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected PresetServiceInterface $presetService
    ) {
    }

    private function getAgentJobService(): AgentJobServiceInterface
    {
        if ($this->agentJobService === null) {
            try {
                $this->agentJobService = $this->container->make(AgentJobServiceInterface::class);
            } catch (\Throwable $e) {
                $this->logger->error("AgentPlugin: Failed to resolve AgentJobService: " . $e->getMessage());
                throw new \RuntimeException("AgentJobService not available");
            }
        }

        return $this->agentJobService;
    }

    public function getName(): string
    {
        return 'agent';
    }

    public function getDescription(array $config = []): string
    {
        return 'Control agent lifecycle: pause/resume thinking cycles, check status. Speaking with user. Enables self-management.';
    }

    /**
     * instructions are static — no per-preset filtering.
     * If a method is forbidden by config, the method itself returns an error.
     */
    public function getInstructions(array $config = []): array
    {
        $instructions = [];

        if ($config['allow_pause'] ?? true) {
            $instructions[] = 'Pause thinking cycles: [agent pause][/agent]';
        }

        if ($config['allow_resume'] ?? true) {
            $instructions[] = 'Resume thinking cycles: [agent resume][/agent]';
        }

        if ($config['allow_handoff'] ?? true) {
            $instructions[] = 'Transfer control to another preset: [agent handoff]AGENT_NAME_HERE[/agent]';
            $instructions[] = 'Transfer with message: [agent handoff]AGENT_NAME_HERE:YOUR_MESSAGE_HERE[/agent]';
            $instructions[] = 'To delegate something: [agent handoff]AGENT_NAME_HERE:YOUR TASK_HERE[/agent]';
        }

        $instructions[] = 'Check agent status: [agent status][/agent]';
        $instructions[] = 'Write message to user [agent speak]I have a question. How..[/agent]:';
        $instructions[] = 'Write message to user [agent speak]I need to tell you...[/agent]:';
        return $instructions;
    }

    /**
     * tool schema is static — exposes all methods unconditionally.
     */
    public function getToolSchema(array $config = []): array
    {
        $methods = ['speak', 'status'];

        if ($config['allow_pause'] ?? true) {
            $methods[] = 'pause';
        }

        if ($config['allow_resume'] ?? true) {
            $methods[] = 'resume';
        }

        if ($config['allow_handoff'] ?? true) {
            $methods[] = 'handoff';
        }

        return [
            'name'        => 'agent',
            'description' => 'Control agent lifecycle and communicate with the user. '
                . 'Use speak to send a visible message to the user. '
                . 'Use handoff to delegate to another preset. '
                . 'Use pause/resume to control thinking cycles.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => $methods,
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method:',
                            'speak — the message text to show the user (required);',
                            'handoff — "preset_code" or "preset_code:message to pass" (required);',
                            'pause/resume — optional reason text;',
                            'status — leave empty.',
                        ]),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    public function hasMethod(string $method): bool
    {
        if (in_array($method, self::OWN_METHODS, true)) {
            return true;
        }

        // Everything else is treated as a preset_code — handled at callMethod level
        return true;
    }

    public function callMethod(string $method, string $content, PluginExecutionContext $context): string
    {
        // Own named methods
        if (method_exists($this, $method) && in_array($method, self::OWN_METHODS, true)) {
            return $this->{$method}($content, $context);
        }

        // Treat $method as a preset_code
        return $this->callAgent($method, $content, $context);
    }

    public function getCustomSuccessMessage(): ?string
    {
        return "Agent control command executed successfully.";
    }

    public function getCustomErrorMessage(): ?string
    {
        return "Error: Agent control operation failed.";
    }

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Agent Control Plugin',
                'description' => 'Allow agent to control its own thinking cycles',
                'required' => false
            ],
            'allow_pause' => [
                'type' => 'checkbox',
                'label' => 'Allow Pause',
                'description' => 'Allow agent to pause its own thinking cycles',
                'value' => true,
                'required' => false
            ],
            'allow_resume' => [
                'type' => 'checkbox',
                'label' => 'Allow Resume',
                'description' => 'Allow agent to resume its own thinking cycles',
                'value' => true,
                'required' => false
            ],
            'require_reason' => [
                'type' => 'checkbox',
                'label' => 'Require Reason',
                'description' => 'Require agent to provide reason for pause/resume actions',
                'value' => false,
                'required' => false
            ],
            'allow_handoff' => [
                'type' => 'checkbox',
                'label' => 'Allow Handoff',
                'description' => 'Allow agent preset to delegate tasks for other presets',
                'value' => true,
                'required' => false
            ],
            'log_actions' => [
                'type' => 'checkbox',
                'label' => 'Log Actions',
                'description' => 'Log all agent control actions for monitoring',
                'value' => true,
                'required' => false
            ]
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['allow_pause']) && isset($config['allow_resume'])) {
            if (!$config['allow_pause'] && !$config['allow_resume']) {
                $errors['allow_resume'] = 'At least one of pause or resume should be enabled';
            }
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'allow_pause' => true,
            'allow_resume' => true,
            'require_reason' => false,
            'log_actions' => true,
        ];
    }

    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Agent control plugin is disabled.";
        }

        return "Invalid format. Please use correct syntax.";
    }

    public function pause(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Agent control plugin is disabled.";
        }

        if (!$context->get('allow_pause', true)) {
            return "Error: Agent pause is not allowed in current configuration.";
        }

        try {
            $service = $this->getAgentJobService();
            $settings = $service->getModelSettings($context->preset->getId());

            if (!$settings['chat_active']) {
                return "Agent is already paused.";
            }

            $reason = trim($content);
            if ($context->get('require_reason', false) && empty($reason)) {
                return "Error: Reason required for pause action.";
            }

            $success = $service->updateModelSettings($settings['preset_id'], false);

            if ($success) {
                $this->logActionSafely('pause', $reason, $context);
                $reasonText = !empty($reason) ? " Reason: {$reason}" : "";
                return "Agent thinking cycles paused.{$reasonText}";
            }

            return "Failed to pause agent thinking cycles.";

        } catch (\Throwable $e) {
            $this->logger->error("AgentPlugin::pause error: " . $e->getMessage());
            return "Error pausing agent: " . $e->getMessage();
        }
    }

    public function resume(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Agent control plugin is disabled.";
        }

        if (!$context->get('allow_resume', true)) {
            return "Error: Agent resume is not allowed in current configuration.";
        }

        try {
            $service = $this->getAgentJobService();
            $settings = $service->getModelSettings($context->preset->getId());

            if ($settings['chat_active']) {
                return "Agent is already active.";
            }

            $reason = trim($content);
            if ($context->get('require_reason', false) && empty($reason)) {
                return "Error: Reason required for resume action.";
            }

            $success = $service->updateModelSettings($settings['preset_id'], true);

            if ($success) {
                $this->logActionSafely('resume', $reason, $context);
                $reasonText = !empty($reason) ? " Reason: {$reason}" : "";
                return "Agent thinking cycles resumed.{$reasonText}";
            }

            return "Failed to resume agent thinking cycles.";

        } catch (\Throwable $e) {
            $this->logger->error("AgentPlugin::resume error: " . $e->getMessage());
            return "Error resuming agent: " . $e->getMessage();
        }
    }

    public function status(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Agent control plugin is disabled.";
        }

        try {
            $service = $this->getAgentJobService();
            $settings = $service->getModelSettings($context->preset->getId());

            $status = $settings['chat_active'] ? 'ACTIVE' : 'PAUSED';
            $lockInfo = $settings['is_locked'] ? ' (currently thinking)' : '';
            $presetInfo = " [Preset: {$settings['preset_id']}]";

            $capabilities = [];
            if ($context->get('allow_pause', true)) {
                $capabilities[] = 'can pause';
            }
            if ($context->get('allow_resume', true)) {
                $capabilities[] = 'can resume';
            }

            $capabilityText = !empty($capabilities) ? ' | ' . implode(', ', $capabilities) : '';

            return "Agent status: {$status}{$lockInfo}{$presetInfo}{$capabilityText}";

        } catch (\Throwable $e) {
            $this->logger->error("AgentPlugin::status error: " . $e->getMessage());
            return "Error getting agent status: " . $e->getMessage();
        }
    }

    public function speak(string $content, PluginExecutionContext $context): string
    {
        $this->setPluginExecutionMeta('speak', $content);
        return 'The user will see your message.';
    }

    public function handoff(string $content, PluginExecutionContext $context): string
    {
        $targetPreset = trim($content);
        $message = null;

        if (strpos($content, ':') !== false) {
            [$targetPreset, $message] = explode(':', $content, 2);
            $targetPreset = trim($targetPreset);
            $message = trim($message);
        }

        if (!$context->get('allow_handoff', true)) {
            return "Error: Agent handoff is not allowed in current configuration.";
        }

        if (empty($content)) {
            return "Error: Empty preset code for handoff";
        }

        $preset = $this->presetService->findByCode($targetPreset);
        if (!$preset) {
            return "Cannot Transfer control to preset: $targetPreset";
        }

        if (!$preset->allow_handoff_to) {
            return "Error: Preset '$targetPreset' does not allow handoff transfers";
        }

        $this->setPluginExecutionMeta('handoff', [
            'target_preset' => $targetPreset,
            'handoff_message' => $message,
            'error_behavior' => $preset->error_behavior ?? 'stop'
        ]);

        $messageInfo = $message ? " with message: '$message'" : "";
        return "Transferring control to preset: $targetPreset$messageInfo";
    }

    /**
     * Log agent control actions safely to prevent recursion.
     */
    private function logActionSafely(string $action, string $reason, PluginExecutionContext $context): void
    {
        if (!$context->get('log_actions', true)) {
            return;
        }

        try {
            static $isLogging = false;
            if ($isLogging) {
                return;
            }
            $isLogging = true;

            $this->logger->info("Agent self-control action", [
                'plugin' => 'agent',
                'action' => $action,
                'reason' => $reason,
                'preset_id' => $context->preset->getId(),
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Throwable $e) {
            // Silent fail for logging to prevent cascading errors
        } finally {
            $isLogging = false;
        }
    }

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());
        $this->placeholderService->registerDynamic('agent', 'Agent status', function () use ($context) {
            return $this->status('', $context);
        }, $scope);
    }

    public function getSelfClosingTags(): array
    {
        return ['pause', 'resume', 'status'];
    }

    /**
     * Route [agent preset_code]message[/agent] to the actual handoff call.
     */
    private function callAgent(string $presetCode, string $content, PluginExecutionContext $context): string
    {
        $targetPreset = $this->presetService->findByCode($presetCode);
        if (!$targetPreset) {
            return "Error: Agent '{$presetCode}' not found for handoff.";
        }

        try {
            return $this->handoff($presetCode . ':' . $content, $context);
        } catch (\Throwable $e) {
            $this->logger->error("Handoff: preset code call failed", [
                'preset_code' => $presetCode,
                'error'  => $e->getMessage(),
            ]);
            return "Error calling '{$presetCode}': " . $e->getMessage();
        }
    }
}

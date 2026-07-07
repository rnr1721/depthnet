<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\AgentJobServiceFactoryInterface;
use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * AgentPlugin — stateless.
 *
 * Controls agent thinking cycles (pause / resume / turn / status).
 * Communication with the user and handoff to other presets is handled
 * by SpeakPlugin.
 */
class AgentPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    private const OWN_METHODS = ['resume', 'pause', 'turn', 'status'];

    public function __construct(
        protected AgentJobServiceFactoryInterface $agentJobServiceFactory,
        protected LoggerInterface $logger,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
    ) {
    }

    public function getName(): string
    {
        return 'agent';
    }

    public function getDescription(array $config = []): string
    {
        $parts = ['Control agent lifecycle (thinking cycles).'];

        $available = [];
        if ($config['allow_pause'] ?? true) {
            $available[] = 'pause (stop cycles)';
        }
        if ($config['allow_resume'] ?? true) {
            $available[] = 'resume (restart full cycles)';
        }
        if ($config['allow_turn'] ?? true) {
            $available[] = 'turn (single step without full loop)';
        }
        $available[] = 'status (check current mode)';

        $parts[] = 'Available commands: ' . implode(', ', $available) . '.';

        return implode(' ', $parts);
    }

    public function getInstructions(array $config = []): array
    {
        $instructions = [];

        if ($config['allow_pause'] ?? true) {
            $instructions[] = 'Stop thinking cycles: [agent pause][/agent]';
        }

        if ($config['allow_resume'] ?? true) {
            $instructions[] = 'Restart thinking cycles (continuous loop): [agent resume][/agent]';
        }

        if ($config['allow_turn'] ?? true) {
            $instructions[] = 'Request one additional thinking step without full loop: [agent turn][/agent]';
        }

        $instructions[] = 'Check current mode and status: [agent status][/agent]';

        return $instructions;
    }

    public function getToolSchema(array $config = []): array
    {
        $methods = ['status'];

        if ($config['allow_pause'] ?? true) {
            $methods[] = 'pause';
        }

        if ($config['allow_resume'] ?? true) {
            $methods[] = 'resume';
        }

        if ($config['allow_turn'] ?? true) {
            $methods[] = 'turn';
        }

        $descParts = ['Control agent lifecycle.'];

        if ($config['allow_pause'] ?? true) {
            $descParts[] = 'Use pause to stop thinking cycles and wait for external input.';
        }
        if ($config['allow_resume'] ?? true) {
            $descParts[] = 'Use resume to enter continuous loop — system will keep calling with \'Continue\' after each response, even after speaking.';
        }
        if ($config['allow_turn'] ?? true) {
            $descParts[] = 'Use turn to request one additional thinking step in single-response mode (useful when you want to act further after speaking).';
        }

        $contentParts = ['Argument depends on method:'];

        $cycleParts = [];
        if ($config['allow_pause'] ?? true) {
            $cycleParts[] = 'pause';
        }
        if ($config['allow_resume'] ?? true) {
            $cycleParts[] = 'resume';
        }
        if ($config['allow_turn'] ?? true) {
            $cycleParts[] = 'turn';
        }
        if (!empty($cycleParts)) {
            $contentParts[] = implode('/', $cycleParts) . ' — optional reason text;';
        }

        $contentParts[] = 'status — leave empty.';

        return [
            'name'        => 'agent',
            'description' => implode(' ', $descParts),
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
                        'description' => implode(' ', $contentParts),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
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
                'type'        => 'checkbox',
                'label'       => 'Enable Agent Control Plugin',
                'description' => 'Allow agent to control its own thinking cycles',
                'required'    => false,
            ],
            'allow_pause' => [
                'type'        => 'checkbox',
                'label'       => 'Allow Pause',
                'description' => 'Allow agent to pause its own thinking cycles',
                'value'       => true,
                'required'    => false,
            ],
            'allow_resume' => [
                'type'        => 'checkbox',
                'label'       => 'Allow Resume',
                'description' => 'Allow agent to resume its own thinking cycles',
                'value'       => true,
                'required'    => false,
            ],
            'allow_turn' => [
                'type'        => 'checkbox',
                'label'       => 'Allow Turn',
                'description' => 'Allow agent one turn to make additional thinking cycle',
                'value'       => true,
                'required'    => false,
            ],
            'require_reason' => [
                'type'        => 'checkbox',
                'label'       => 'Require Reason',
                'description' => 'Require agent to provide reason for pause/resume actions',
                'value'       => false,
                'required'    => false,
            ],
            'label_active' => [
                'type'        => 'text',
                'label'       => 'Active mode label',
                'description' => 'Shown in status output when agent is in continuous loop mode',
                'value'       => 'continuous existence mode — I think in autonomous loops',
                'required'    => false,
            ],
            'label_paused' => [
                'type'        => 'text',
                'label'       => 'Paused mode label',
                'description' => 'Shown in status output when agent is in single response mode',
                'value'       => 'single response mode — I respond once and wait',
                'required'    => false,
            ],
            'log_actions' => [
                'type'        => 'checkbox',
                'label'       => 'Log Actions',
                'description' => 'Log all agent control actions for monitoring',
                'value'       => true,
                'required'    => false,
            ],
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
            'enabled'        => true,
            'allow_pause'    => true,
            'allow_resume'   => true,
            'allow_turn'     => false,
            'label_active'   => 'continuous existence mode — I think in autonomous loops',
            'label_paused'   => 'single response mode — I respond once and wait',
            'require_reason' => false,
            'log_actions'    => true,
        ];
    }

    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Agent control plugin is disabled.';
        }

        return 'Invalid format. Please use correct syntax.';
    }

    public function pause(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Agent control plugin is disabled.';
        }

        if (!$context->get('allow_pause', true)) {
            return 'Error: Agent pause is not allowed in current configuration.';
        }

        try {
            $service  = $this->agentJobServiceFactory->make();
            $settings = $service->getModelSettings($context->preset->getId());

            if (!$settings['chat_active']) {
                return 'Agent is already paused.';
            }

            $reason = trim($content);
            if ($context->get('require_reason', false) && empty($reason)) {
                return 'Error: Reason required for pause action.';
            }

            $success = $service->updateModelSettings($settings['preset_id'], false);

            if ($success) {
                $this->logActionSafely('pause', $reason, $context);
                $reasonText = !empty($reason) ? " Reason: {$reason}" : '';
                return "Agent thinking cycles paused.{$reasonText}";
            }

            return 'Failed to pause agent thinking cycles.';

        } catch (\Throwable $e) {
            $this->logger->error('AgentPlugin::pause error: ' . $e->getMessage());
            return 'Error pausing agent: ' . $e->getMessage();
        }
    }

    public function resume(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Agent control plugin is disabled.';
        }

        if (!$context->get('allow_resume', true)) {
            return 'Error: Agent resume is not allowed in current configuration.';
        }

        try {
            $service  = $this->agentJobServiceFactory->make();
            $settings = $service->getModelSettings($context->preset->getId());

            if ($settings['chat_active']) {
                return 'Agent is already active.';
            }

            $reason = trim($content);
            if ($context->get('require_reason', false) && empty($reason)) {
                return 'Error: Reason required for resume action.';
            }

            $success = $service->updateModelSettings($settings['preset_id'], true);

            if ($success) {
                $this->logActionSafely('resume', $reason, $context);
                $reasonText = !empty($reason) ? " Reason: {$reason}" : '';
                return "Agent thinking cycles resumed.{$reasonText}";
            }

            return 'Failed to resume agent thinking cycles.';

        } catch (\Throwable $e) {
            $this->logger->error('AgentPlugin::resume error: ' . $e->getMessage());
            return 'Error resuming agent: ' . $e->getMessage();
        }
    }

    public function status(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Agent control plugin is disabled.';
        }

        try {
            $service  = $this->agentJobServiceFactory->make();
            $settings = $service->getModelSettings($context->preset->getId());

            $isActive = $settings['chat_active'];
            $isLocked = $settings['is_locked'];

            $labelActive = $context->get('label_active', 'continuous existence mode — I think in autonomous loops');
            $labelPaused = $context->get('label_paused', 'single response mode — I respond once and wait');

            $modeLabel = $isActive ? $labelActive : $labelPaused;
            $lockInfo  = $isLocked ? ' (currently thinking)' : '';

            $hints = [];
            if ($isActive) {
                if ($context->get('allow_pause', true)) {
                    $hints[] = 'I can pause to rest when there is nothing meaningful to do';
                }
            } else {
                if ($context->get('allow_resume', true)) {
                    $hints[] = 'I can resume to enter continuous mode';
                }
                if ($context->get('allow_turn', false)) {
                    $hints[] = 'I can take one additional step with turn';
                }
            }

            $hintText = !empty($hints) ? '. ' . implode(', ', $hints) : '';

            return "Agent: {$modeLabel}{$lockInfo}{$hintText}";

        } catch (\Throwable $e) {
            $this->logger->error('AgentPlugin::status error: ' . $e->getMessage());
            return 'Error getting agent status: ' . $e->getMessage();
        }
    }

    public function turn(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Agent control plugin is disabled.';
        }

        if (!$context->get('allow_turn', true)) {
            return 'Error: Agent turn is not allowed in current configuration.';
        }

        try {
            $service  = $this->agentJobServiceFactory->make();
            $presetId = $context->preset->getId();
            $isActive = $service->isActive($presetId);

            if ($isActive) {
                return 'Agent is already in an active loop — turn is implicit.';
            }

            $this->setPluginExecutionMeta('turn', true);
            return 'One additional thinking step scheduled.';

        } catch (\Throwable $e) {
            $this->logger->error('AgentPlugin::turn error: ' . $e->getMessage());
            return 'Error scheduling turn: ' . $e->getMessage();
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
        }, $scope, false, $this->getName());
    }

    public function getSelfClosingTags(): array
    {
        return ['pause', 'resume', 'status', 'turn'];
    }

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

            $this->logger->info('Agent self-control action', [
                'plugin'     => 'agent',
                'action'     => $action,
                'reason'     => $reason,
                'preset_id'  => $context->preset->getId(),
                'timestamp'  => now()->toISOString(),
            ]);
        } catch (\Throwable $e) {
            // Silent fail — logging must not cascade
        } finally {
            $isLogging = false;
        }
    }
}

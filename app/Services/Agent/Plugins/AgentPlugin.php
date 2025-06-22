<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use App\Services\Agent\Plugins\Traits\PluginPresetTrait;
use Psr\Log\LoggerInterface;
use Illuminate\Contracts\Container\Container;

/**
 * AgentPlugin class
 *
 * Allows the AI agent to control its own thinking cycles - pause, resume, and check status.
 * Provides self-management capabilities for autonomous agents.
 */
class AgentPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;
    use PluginConfigTrait;

    /** @var AgentJobServiceInterface|null Lazy-loaded service */
    private ?AgentJobServiceInterface $agentJobService = null;

    public function __construct(
        protected Container $container,
        protected LoggerInterface $logger,
        protected PlaceholderServiceInterface $placeholderService
    ) {
        $this->initializeConfig();
    }

    /**
     * Get AgentJobService with lazy loading to prevent circular dependency
     */
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

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'agent';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Control agent lifecycle: pause/resume thinking cycles, check status. Enables self-management.';
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        $instructions = [];

        if ($this->config['allow_pause'] ?? true) {
            $instructions[] = 'Pause thinking cycles: [agent pause][/agent]';
        }

        if ($this->config['allow_resume'] ?? true) {
            $instructions[] = 'Resume thinking cycles: [agent resume][/agent]';
        }

        $instructions[] = 'Check agent status: [agent status][/agent]';

        return $instructions;
    }

    /**
     * @inheritDoc
     */
    public function getCustomSuccessMessage(): ?string
    {
        return "Agent control command executed successfully.";
    }

    /**
     * @inheritDoc
     */
    public function getCustomErrorMessage(): ?string
    {
        return "Error: Agent control operation failed.";
    }

    /**
     * @inheritDoc
     */
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
            'log_actions' => [
                'type' => 'checkbox',
                'label' => 'Log Actions',
                'description' => 'Log all agent control actions for monitoring',
                'value' => true,
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

        // If both pause and resume are disabled, plugin becomes mostly useless
        if (isset($config['allow_pause']) && isset($config['allow_resume'])) {
            if (!$config['allow_pause'] && !$config['allow_resume']) {
                $errors['allow_resume'] = 'At least one of pause or resume should be enabled';
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
            'allow_pause' => true,
            'allow_resume' => true,
            'require_reason' => false,
            'log_actions' => true
        ];
    }

    /**
     * @inheritDoc
     */
    public function testConnection(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            // Test if we can get agent service without hanging
            $service = $this->getAgentJobService();

            // Quick test - just check if service is available
            return $service !== null;

        } catch (\Exception $e) {
            $this->logger->error("AgentPlugin::testConnection error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function execute(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Agent control plugin is disabled.";
        }

        return "Invalid format. Use '[agent pause][/agent]', '[agent resume][/agent]', or '[agent status][/agent]'";
    }

    /**
     * Pause agent thinking cycles
     *
     * @param string $content Optional reason for pausing
     * @return string
     */
    public function pause(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Agent control plugin is disabled.";
        }

        if (!($this->config['allow_pause'] ?? true)) {
            return "Error: Agent pause is not allowed in current configuration.";
        }

        try {
            $service = $this->getAgentJobService();
            $settings = $service->getModelSettings();

            if (!$settings['chat_active']) {
                return "Agent is already paused.";
            }

            $reason = trim($content);
            if (($this->config['require_reason'] ?? false) && empty($reason)) {
                return "Error: Reason required for pause action.";
            }

            $success = $service->updateModelSettings(
                $settings['preset_id'],
                false
            );

            if ($success) {
                $this->logActionSafely('pause', $reason);
                $reasonText = !empty($reason) ? " Reason: {$reason}" : "";
                return "Agent thinking cycles paused.{$reasonText}";
            } else {
                return "Failed to pause agent thinking cycles.";
            }

        } catch (\Throwable $e) {
            $this->logger->error("AgentPlugin::pause error: " . $e->getMessage());
            return "Error pausing agent: " . $e->getMessage();
        }
    }

    /**
     * Resume agent thinking cycles
     *
     * @param string $content Optional reason for resuming
     * @return string
     */
    public function resume(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Agent control plugin is disabled.";
        }

        if (!($this->config['allow_resume'] ?? true)) {
            return "Error: Agent resume is not allowed in current configuration.";
        }

        try {
            $service = $this->getAgentJobService();
            $settings = $service->getModelSettings();

            if ($settings['chat_active']) {
                return "Agent is already active.";
            }

            $reason = trim($content);
            if (($this->config['require_reason'] ?? false) && empty($reason)) {
                return "Error: Reason required for resume action.";
            }

            $success = $service->updateModelSettings(
                $settings['preset_id'],
                true
            );

            if ($success) {
                $this->logActionSafely('resume', $reason);
                $reasonText = !empty($reason) ? " Reason: {$reason}" : "";
                return "Agent thinking cycles resumed.{$reasonText}";
            } else {
                return "Failed to resume agent thinking cycles.";
            }

        } catch (\Throwable $e) {
            $this->logger->error("AgentPlugin::resume error: " . $e->getMessage());
            return "Error resuming agent: " . $e->getMessage();
        }
    }

    /**
     * Get current agent status
     *
     * @param string $content Unused
     * @return string
     */
    public function status(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Agent control plugin is disabled.";
        }

        try {
            $service = $this->getAgentJobService();
            $settings = $service->getModelSettings();

            $status = $settings['chat_active'] ? 'ACTIVE' : 'PAUSED';
            $lockInfo = $settings['is_locked'] ? ' (currently thinking)' : '';
            $presetInfo = " [Preset: {$settings['preset_id']}]";

            $capabilities = [];
            if ($this->config['allow_pause'] ?? true) {
                $capabilities[] = 'can pause';
            }
            if ($this->config['allow_resume'] ?? true) {
                $capabilities[] = 'can resume';
            }

            $capabilityText = !empty($capabilities) ? ' | ' . implode(', ', $capabilities) : '';

            return "Agent status: {$status}{$lockInfo}{$presetInfo}{$capabilityText}";

        } catch (\Throwable $e) {
            $this->logger->error("AgentPlugin::status error: " . $e->getMessage());
            return "Error getting agent status: " . $e->getMessage();
        }
    }

    /**
     * Log agent control actions safely to prevent recursion
     *
     * @param string $action Action performed (pause/resume)
     * @param string $reason Reason for action
     * @return void
     */
    private function logActionSafely(string $action, string $reason): void
    {
        if (!($this->config['log_actions'] ?? true)) {
            return;
        }

        try {
            // Prevent recursion by checking if we're already logging
            static $isLogging = false;

            if ($isLogging) {
                return;
            }

            $isLogging = true;

            $this->logger->info("Agent self-control action", [
                'plugin' => 'agent',
                'action' => $action,
                'reason' => $reason,
                'preset_id' => $this->preset?->id ?? 'unknown',
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Throwable $e) {
            // Silent fail for logging to prevent cascading errors
        } finally {
            $isLogging = false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getMergeSeparator(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function canBeMerged(): bool
    {
        return false;
    }

    public function pluginReady(): void
    {
        $this->placeholderService->registerDynamic('agent', 'Agent status', function () {
            return $this->status('');
        });

    }

}

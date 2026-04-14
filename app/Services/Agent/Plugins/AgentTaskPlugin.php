<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Orchestrator\AgentTaskServiceInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * AgentTaskPlugin — task management for orchestrated agent workflows.
 *
 * Designed for three types of presets within an agent:
 *
 * Planner:
 *   [task]title | role: executor | Detailed description[/task]   — create & assign
 *   [task list][/task]                                           — active tasks
 *   [task show]42[/task]                                         — task details
 *
 * Role (executor):
 *   [task done]42 | result here[/task]     — mark completed with result
 *   [task fail]42 | reason here[/task]     — report failure
 *
 * Validator:
 *   [task approve]42 | looks good[/task]   — approve result
 *   [task reject]42 | needs more[/task]    — reject with feedback
 */
class AgentTaskPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected AgentTaskServiceInterface $agentTaskService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected LoggerInterface $logger
    ) {
        $this->initializeConfig();
    }

    public function getName(): string
    {
        return 'task';
    }

    public function getDescription(): string
    {
        return 'Orchestrated task management. Planner creates and assigns tasks to roles. Roles complete or fail them. Validators approve or reject results. Orchestrator handles routing automatically.';
    }

    public function getInstructions(): array
    {
        return [
            '— PLANNER —',
            'Create task: [task]Write a summary | role: writer | Summarize the research findings[/task]',
            'Create unassigned task: [task]Review approach[/task]',
            'List active tasks: [task list][/task]',
            'List all tasks: [task list]all[/task]',
            'Show task details: [task show]42[/task]',
            '— ROLE (executor) —',
            'Mark done: [task done]42 | The summary is ready: ...[/task]',
            'Report failure: [task fail]42 | Could not access the data source[/task]',
            '— VALIDATOR —',
            'Approve result: [task approve]42 | Result meets requirements[/task]',
            'Reject result: [task reject]42 | Missing key sections, please revise[/task]',
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Agent Task Plugin',
                'description' => 'Allow task management for orchestrated agent workflows',
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
        return ['enabled' => true];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    public function testConnection(): bool
    {
        return $this->isEnabled();
    }

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
        return ['list'];
    }

    /**
     * Default execute — create a new task.
     *
     * Format: "title | role: roleCode | description"
     * Or:     "title | description"
     * Or:     "title"
     */
    public function execute(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Task plugin is disabled.';
        }

        $agent = $this->agentTaskService->findAgentForPreset($preset);
        if (!$agent) {
            return 'Error: This preset is not part of any active agent.';
        }

        // Parse: title | role: xxx | description
        // or:    title | description
        $parts       = array_map('trim', explode('|', $content, 3));
        $title       = $parts[0] ?? '';
        $assignedRole = null;
        $description  = null;

        if (isset($parts[1])) {
            if (preg_match('/^role:\s*(\S+)$/i', $parts[1], $m)) {
                $assignedRole = $m[1];
                $description  = $parts[2] ?? null;
            } else {
                $description = $parts[1];
                // parts[2] ignored if no role declared
            }
        }

        $result = $this->agentTaskService->createTask(
            $agent,
            $title,
            $description,
            $assignedRole,
            createdByRole: 'planner'
        );

        return $result['message'];
    }

    /**
     * Mark task as done with result.
     * Format: "taskId | result text"
     */
    public function done(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Task plugin is disabled.';
        }

        $agent = $this->agentTaskService->findAgentForPreset($preset);
        if (!$agent) {
            return 'Error: This preset is not part of any active agent.';
        }

        $parts = explode('|', $content, 2);
        if (count($parts) !== 2) {
            return 'Error: Invalid format. Please use correct syntax';
        }

        $taskId = $this->parseTaskId($parts[0]);
        $result = trim($parts[1]);

        return $this->agentTaskService->completeTask($agent, $taskId, $result)['message'];
    }

    /**
     * Report task failure.
     * Format: "taskId | reason"
     */
    public function fail(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Task plugin is disabled.';
        }

        $agent = $this->agentTaskService->findAgentForPreset($preset);
        if (!$agent) {
            return 'Error: This preset is not part of any active agent.';
        }

        $parts = explode('|', $content, 2);
        if (count($parts) !== 2) {
            return 'Error: Invalid format. Please use correct syntax';
        }

        $taskId = $this->parseTaskId($parts[0]);
        $reason = trim($parts[1]);

        return $this->agentTaskService->failTask($agent, $taskId, $reason)['message'];
    }

    /**
     * Approve validated task.
     * Format: "taskId | notes"
     */
    public function approve(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Task plugin is disabled.';
        }

        $agent = $this->agentTaskService->findAgentForPreset($preset);
        if (!$agent) {
            return 'Error: This preset is not part of any active agent.';
        }

        $parts  = explode('|', $content, 2);
        $taskId = $this->parseTaskId($parts[0]);
        $notes  = trim($parts[1] ?? '');

        return $this->agentTaskService->validateTask($agent, $taskId, true, $notes)['message'];
    }

    /**
     * Reject validated task with feedback.
     * Format: "taskId | feedback"
     */
    public function reject(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Task plugin is disabled.';
        }

        $agent = $this->agentTaskService->findAgentForPreset($preset);
        if (!$agent) {
            return 'Error: This preset is not part of any active agent.';
        }

        $parts = explode('|', $content, 2);
        if (count($parts) !== 2) {
            return 'Error: Invalid format. Please use correct syntax';
        }

        $taskId   = (int) trim($parts[0]);
        $feedback = trim($parts[1]);

        return $this->agentTaskService->validateTask($agent, $taskId, false, $feedback)['message'];
    }

    /**
     * List tasks.
     * Default: active only. Pass "all" or status name for filtering.
     */
    public function list(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Task plugin is disabled.';
        }

        $agent = $this->agentTaskService->findAgentForPreset($preset);
        if (!$agent) {
            return 'Error: This preset is not part of any active agent.';
        }

        $status = trim($content) ?: null;
        return $this->agentTaskService->listTasks($agent, $status)['message'];
    }

    /**
     * Show single task detail.
     * Format: "taskId"
     */
    public function show(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Task plugin is disabled.';
        }

        $agent = $this->agentTaskService->findAgentForPreset($preset);
        if (!$agent) {
            return 'Error: This preset is not part of any active agent.';
        }

        $taskId = $this->parseTaskId($content);
        return $this->agentTaskService->showTask($agent, $taskId)['message'];
    }

    /**
     * Register [[agent_tasks]] placeholder when plugin is applied to a preset.
     * Only registers if this preset belongs to an agent.
     */
    public function pluginReady(AiPreset $preset): void
    {
        $agent = $this->agentTaskService->findAgentForPreset($preset);
        if (!$agent) {
            return;
        }

        $scope = $this->shortcodeScopeResolver->preset($preset->getId());

        $this->placeholderService->registerDynamic(
            'agent_tasks',
            'Active tasks for this agent with status and assigned role',
            function () use ($agent) {
                return $this->agentTaskService->getTasksForContext($agent);
            },
            $scope
        );
    }

    /**
     * Extract task ID from model output.
     * Strips any non-numeric characters — handles #14, №14, task14, etc.
     */
    private function parseTaskId(string $raw): int
    {
        return (int) preg_replace('/\D/', '', $raw);
    }

}

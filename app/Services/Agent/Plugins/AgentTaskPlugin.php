<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Orchestrator\AgentTaskServiceInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginHasLanguageSettingsTrait;
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
    use PluginHasLanguageSettingsTrait;

    public function __construct(
        protected AgentTaskServiceInterface $agentTaskService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'task';
    }

    public function getDescription(array $config = []): string
    {
        return 'Orchestrated task management. Planner creates and assigns tasks to roles. Roles complete or fail them. Validators approve or reject results. Orchestrator handles routing automatically.';
    }

    public function getInstructions(array $config = []): array
    {
        $instructions = [
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

        $warning = $this->buildLanguageWarning($config, 'task_language', 'tasks, descriptions, and comments');
        if ($warning) {
            array_unshift($instructions, $warning);
        }

        return $instructions;
    }

    /**
     * Tool schema for tool_calls mode.
     */
    public function getToolSchema(array $config = []): array
    {
        $langInstruction = $this->buildLanguageInstruction($config, 'task_language');

        return [
            'name'        => 'task',
            'description' => 'Task management for orchestrated agent workflows. '
                . 'Planner creates and assigns tasks to roles. Roles complete or fail them. '
                . 'Validators approve or reject results. Orchestrator handles routing automatically. '
                . $langInstruction
                . 'Active tasks are always visible via [[agent_tasks]] placeholder.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['execute', 'done', 'fail', 'approve', 'reject', 'list', 'show'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'execute (create task): "title" or "title | role: roleCode | description".',
                            'Example: "Write market summary | role: writer | Focus on Q1 2025 data".',
                            'done (complete task): "taskId | result text".',
                            'Example: "42 | Summary written: Q1 growth was 15%".',
                            'fail (report failure): "taskId | reason".',
                            'Example: "42 | Data source unavailable".',
                            'approve (validate success): "taskId | notes (optional)".',
                            'Example: "42 | Looks good, meets requirements".',
                            'reject (validate failure): "taskId | feedback".',
                            'Example: "42 | Missing Q1 breakdown, please revise".',
                            'list: empty for active tasks, "all" for everything, or status name.',
                            'show: taskId only.',
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
                'label'       => 'Enable Agent Task Plugin',
                'description' => 'Allow task management for orchestrated agent workflows',
                'required'    => false,
            ],
            'task_language' => $this->getLanguageConfigField(
                'Task Language',
                'Force language for tasks, descriptions, and comments. Model will be instructed accordingly.'
            ),
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['task_language'])) {
            $valid = array_keys($this->supportedLanguages);
            if (!in_array($config['task_language'], $valid, true)) {
                $errors['task_language'] = 'Invalid language selection.';
            }
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return array_merge(
            ['enabled' => false],
            $this->getDefaultLanguageConfig('task_language')
        );
    }

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
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Task plugin is disabled.';
        }

        $agent = $this->agentTaskService->findAgentForPreset($context->preset);
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
    public function done(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Task plugin is disabled.';
        }

        $agent = $this->agentTaskService->findAgentForPreset($context->preset);
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
    public function fail(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Task plugin is disabled.';
        }

        $agent = $this->agentTaskService->findAgentForPreset($context->preset);
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
    public function approve(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Task plugin is disabled.';
        }

        $agent = $this->agentTaskService->findAgentForPreset($context->preset);
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
    public function reject(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Task plugin is disabled.';
        }

        $agent = $this->agentTaskService->findAgentForPreset($context->preset);
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
    public function list(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Task plugin is disabled.';
        }

        $agent = $this->agentTaskService->findAgentForPreset($context->preset);
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
    public function show(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Task plugin is disabled.';
        }

        $agent = $this->agentTaskService->findAgentForPreset($context->preset);
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
    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $agent = $this->agentTaskService->findAgentForPreset($context->preset);
        if (!$agent) {
            return;
        }

        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());

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

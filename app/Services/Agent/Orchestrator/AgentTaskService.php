<?php

namespace App\Services\Agent\Orchestrator;

use App\Contracts\Agent\Orchestrator\AgentTaskServiceInterface;
use App\Contracts\Agent\Orchestrator\OrchestratorFactoryInterface;
use App\Models\Agent;
use App\Models\AgentRole;
use App\Models\AgentTask;
use App\Models\AiPreset;
use Psr\Log\LoggerInterface;

/**
 * AgentTaskService — manages task lifecycle, UI data formatting, and agent lookup.
 *
 * All model access is centralised here — controllers and plugins
 * never touch Eloquent models directly.
 */
class AgentTaskService implements AgentTaskServiceInterface
{
    public function __construct(
        protected OrchestratorFactoryInterface $orchestratorFactory,
        protected AgentTask $agentTaskModel,
        protected Agent $agentModel,
        protected AgentRole $agentRoleModel,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function createTask(
        Agent $agent,
        string $title,
        ?string $description = null,
        ?string $assignedRole = null,
        ?string $createdByRole = null,
        ?int $parentTaskId = null
    ): array {
        try {
            $title = trim($title);
            if (empty($title)) {
                return ['success' => false, 'message' => 'Error: Task title cannot be empty.'];
            }

            if ($assignedRole && !$this->roleExists($agent, $assignedRole)) {
                return ['success' => false, 'message' => "Error: Role '{$assignedRole}' not found in this agent."];
            }

            $position = $this->agentTaskModel->where('agent_id', $agent->id)->max('position') + 1;

            $task = $this->agentTaskModel->create([
                'agent_id'        => $agent->id,
                'parent_task_id'  => $parentTaskId,
                'title'           => $title,
                'description'     => $description ? trim($description) : null,
                'assigned_role'   => $assignedRole,
                'created_by_role' => $createdByRole ?? 'planner',
                'status'          => AgentTask::STATUS_PENDING,
                'position'        => $position,
            ]);

            if ($assignedRole) {
                $this->orchestratorFactory->make()->tick($agent);
            }

            return [
                'success' => true,
                'message' => "Task #{$task->id} created: {$title}"
                    . ($assignedRole ? " → assigned to: {$assignedRole}" : ' (unassigned)'),
                'task'    => $task,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('AgentTaskService::createTask error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error creating task: ' . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function completeTask(Agent $agent, int $taskId, string $result): array
    {
        try {
            $task = $this->findTask($agent, $taskId);
            if (!$task) {
                return ['success' => false, 'message' => "Error: Task #{$taskId} not found."];
            }

            if ($task->isTerminal()) {
                return ['success' => false, 'message' => "Error: Task #{$taskId} is already in terminal state ({$task->status})."];
            }

            $this->orchestratorFactory->make()->onTaskCompleted($task, trim($result));

            return ['success' => true, 'message' => "Task #{$taskId} marked as completed."];

        } catch (\Throwable $e) {
            $this->logger->error('AgentTaskService::completeTask error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error completing task: ' . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function failTask(Agent $agent, int $taskId, string $reason): array
    {
        try {
            $task = $this->findTask($agent, $taskId);
            if (!$task) {
                return ['success' => false, 'message' => "Error: Task #{$taskId} not found."];
            }

            if ($task->isTerminal()) {
                return ['success' => false, 'message' => "Error: Task #{$taskId} is already in terminal state ({$task->status})."];
            }

            $this->orchestratorFactory->make()->onTaskFailed($task, trim($reason));

            return ['success' => true, 'message' => "Task #{$taskId} reported as failed: {$reason}"];

        } catch (\Throwable $e) {
            $this->logger->error('AgentTaskService::failTask error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error failing task: ' . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function validateTask(Agent $agent, int $taskId, bool $approved, string $notes): array
    {
        try {
            $task = $this->findTask($agent, $taskId);
            if (!$task) {
                return ['success' => false, 'message' => "Error: Task #{$taskId} not found."];
            }

            if ($task->getStatus() !== AgentTask::STATUS_VALIDATING) {
                return ['success' => false, 'message' => "Error: Task #{$taskId} is not in validating status (current: {$task->status})."];
            }

            $this->orchestratorFactory->make()->onValidationResult($task, $approved, trim($notes));

            $verdict = $approved ? 'approved' : 'rejected';
            return ['success' => true, 'message' => "Task #{$taskId} {$verdict}."];

        } catch (\Throwable $e) {
            $this->logger->error('AgentTaskService::validateTask error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error validating task: ' . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function setTaskStatus(Agent $agent, int $taskId, string $status): array
    {
        try {
            $task = $this->findTask($agent, $taskId);
            if (!$task) {
                return ['success' => false, 'message' => "Error: Task #{$taskId} not found."];
            }

            $task->update(['status' => $status]);

            return ['success' => true, 'message' => "Task #{$taskId} status set to {$status}."];

        } catch (\Throwable $e) {
            $this->logger->error('AgentTaskService::setTaskStatus error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating task status: ' . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function listTasks(Agent $agent, ?string $status = null): array
    {
        try {
            $tasks = $this->queryTasks($agent, $status)->get();

            if ($tasks->isEmpty()) {
                return ['success' => true, 'message' => 'No tasks found.'];
            }

            $lines = [];
            foreach ($tasks as $task) {
                $line = "#{$task->id} [{$task->status}] {$task->title}";
                if ($task->assigned_role) {
                    $line .= " → {$task->assigned_role}";
                }
                if ($task->attempts > 0) {
                    $line .= " (attempt {$task->attempts})";
                }
                $lines[] = $line;
            }

            return ['success' => true, 'message' => implode("\n", $lines)];

        } catch (\Throwable $e) {
            $this->logger->error('AgentTaskService::listTasks error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error listing tasks: ' . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function showTask(Agent $agent, int $taskId): array
    {
        try {
            $task = $this->findTask($agent, $taskId);
            if (!$task) {
                return ['success' => false, 'message' => "Error: Task #{$taskId} not found."];
            }

            $lines = [
                "Task #{$task->id} [{$task->status}]: {$task->title}",
                "Role: " . ($task->assigned_role ?? 'unassigned'),
                "Attempts: {$task->attempts}",
            ];

            if ($task->description) {
                $lines[] = "Description: {$task->description}";
            }
            if ($task->result) {
                $lines[] = "Result: {$task->result}";
            }
            if ($task->validator_notes) {
                $lines[] = "Validator notes: {$task->validator_notes}";
            }

            return ['success' => true, 'message' => implode("\n", $lines)];

        } catch (\Throwable $e) {
            $this->logger->error('AgentTaskService::showTask error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error showing task: ' . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function getStructuredTasks(Agent $agent, ?string $statusFilter = null): array
    {
        return $this->queryTasks($agent, $statusFilter)
            ->get()
            ->map(fn ($task) => [
                'id'              => $task->id,
                'title'           => $task->title,
                'description'     => $task->description,
                'assigned_role'   => $task->assigned_role,
                'status'          => $task->status,
                'result'          => $task->result,
                'validator_notes' => $task->validator_notes,
                'attempts'        => $task->attempts,
                'created_by_role' => $task->created_by_role,
                'position'        => $task->position,
                'created_at'      => $task->created_at?->format('Y-m-d H:i'),
                'updated_at'      => $task->updated_at?->format('Y-m-d H:i'),
            ])
            ->values()
            ->all();
    }

    /**
     * @inheritDoc
     */
    public function deleteTask(Agent $agent, int $taskId): array
    {
        try {
            $task = $this->findTask($agent, $taskId);
            if (!$task) {
                return ['success' => false, 'message' => "Error: Task #{$taskId} not found."];
            }

            $task->delete();

            return ['success' => true, 'message' => "Task #{$taskId} deleted."];

        } catch (\Throwable $e) {
            $this->logger->error('AgentTaskService::deleteTask error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting task: ' . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function clearTasks(Agent $agent): bool
    {
        try {
            $this->agentTaskModel->where('agent_id', $agent->id)->delete();
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('AgentTaskService::clearTasks error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     *
     * Shows only active tasks to keep planner context lean.
     */
    public function getTasksForContext(Agent $agent): string
    {
        $tasks = $this->agentTaskModel->where('agent_id', $agent->id)
            ->active()
            ->ordered()
            ->get();

        if ($tasks->isEmpty()) {
            return 'none';
        }

        $lines = [];
        foreach ($tasks as $task) {
            $line = "#{$task->id} [{$task->status}] {$task->title}";
            if ($task->assigned_role) {
                $line .= " → {$task->assigned_role}";
            }
            if ($task->result && $task->status === AgentTask::STATUS_VALIDATING) {
                $line .= "\n  Pending validation: " . mb_substr($task->result, 0, 200);
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @inheritDoc
     */
    public function findTask(Agent $agent, int $taskId): ?AgentTask
    {
        return $this->agentTaskModel->where('agent_id', $agent->id)->find($taskId);
    }

    /**
     * @inheritDoc
     */
    public function findAgent(int $agentId): ?Agent
    {
        return $this->agentModel->where('id', $agentId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @inheritDoc
     */
    public function findAgentForPreset(AiPreset $preset): ?Agent
    {
        // Check if this preset is a planner
        $agent = $this->agentModel->where('planner_preset_id', $preset->id)
            ->where('is_active', true)
            ->first();

        if ($agent) {
            return $agent;
        }

        // Check if this preset is assigned to a role or is a validator
        $role = $this->agentRoleModel->where('preset_id', $preset->id)
            ->orWhere('validator_preset_id', $preset->id)
            ->first();

        return $role?->agent()->where('is_active', true)->first();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build base query with status filter applied.
     * Centralises filter logic used by listTasks() and getStructuredTasks().
     *
     * null   → active (non-terminal) tasks only
     * 'all'  → no filter
     * other  → exact status match
     */
    private function queryTasks(Agent $agent, ?string $status): \Illuminate\Database\Eloquent\Builder
    {
        $query = $this->agentTaskModel->where('agent_id', $agent->id)->ordered();

        if ($status === 'all') {
            // no filter
        } elseif ($status) {
            $query->where('status', $status);
        } else {
            $query->active();
        }

        return $query;
    }

    /**
     * Check if role code exists in agent.
     */
    private function roleExists(Agent $agent, string $code): bool
    {
        return $agent->roles()->where('code', $code)->exists();
    }
}

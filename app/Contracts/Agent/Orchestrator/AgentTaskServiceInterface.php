<?php

namespace App\Contracts\Agent\Orchestrator;

use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AiPreset;

interface AgentTaskServiceInterface
{
    /**
     * Create a new task for the agent.
     *
     * @param Agent       $agent
     * @param string      $title
     * @param string|null $description
     * @param string|null $assignedRole  Role code from agent_roles
     * @param string|null $createdByRole Who created this task (planner / role code / admin)
     * @param int|null    $parentTaskId
     * @return array{success: bool, message: string, task?: AgentTask}
     */
    public function createTask(
        Agent $agent,
        string $title,
        ?string $description = null,
        ?string $assignedRole = null,
        ?string $createdByRole = null,
        ?int $parentTaskId = null
    ): array;

    /**
     * Mark task as completed with result.
     *
     * @return array{success: bool, message: string}
     */
    public function completeTask(Agent $agent, int $taskId, string $result): array;

    /**
     * Mark task as failed with reason.
     *
     * @return array{success: bool, message: string}
     */
    public function failTask(Agent $agent, int $taskId, string $reason): array;

    /**
     * Submit validation result for a task in validating status.
     *
     * @return array{success: bool, message: string}
     */
    public function validateTask(Agent $agent, int $taskId, bool $approved, string $notes): array;

    /**
     * Manually override task status (admin use).
     *
     * @return array{success: bool, message: string}
     */
    public function setTaskStatus(Agent $agent, int $taskId, string $status): array;

    /**
     * List tasks with optional status filter.
     * null = active only, 'all' = everything, any status string = that status.
     *
     * @return array{success: bool, message: string}
     */
    public function listTasks(Agent $agent, ?string $status = null): array;

    /**
     * Show single task detail.
     *
     * @return array{success: bool, message: string}
     */
    public function showTask(Agent $agent, int $taskId): array;

    /**
     * Return structured task data for UI rendering.
     * Applies same status filter logic as listTasks.
     *
     * @return array<int, array>
     */
    public function getStructuredTasks(Agent $agent, ?string $statusFilter = null): array;

    /**
     * Delete a single task by ID scoped to agent.
     *
     * @return array{success: bool, message: string}
     */
    public function deleteTask(Agent $agent, int $taskId): array;

    /**
     * Delete all tasks for an agent.
     *
     * @param Agent $agent
     * @return boolean
     */
    public function clearTasks(Agent $agent): bool;

    /**
     * Get tasks formatted for [[agent_tasks]] placeholder in planner system prompt.
     *
     * @param Agent $agent
     * @return string
     */
    public function getTasksForContext(Agent $agent): string;

    /**
     * Find task by ID scoped to agent. Returns null if not found.
     *
     * @param Agent $agent
     * @param integer $taskId
     * @return AgentTask|null
     */
    public function findTask(Agent $agent, int $taskId): ?AgentTask;

    /**
     * Find active agent by ID. Returns null if not found or inactive.
     *
     * @param integer $agentId
     * @return Agent|null
     */
    public function findAgent(int $agentId): ?Agent;

    /**
     * Find agent that owns the given preset (as planner or role).
     * Used by AgentTaskPlugin to resolve agent context from current AiPreset.
     *
     * @param AiPreset $preset
     * @return Agent|null
     */
    public function findAgentForPreset(AiPreset $preset): ?Agent;
}

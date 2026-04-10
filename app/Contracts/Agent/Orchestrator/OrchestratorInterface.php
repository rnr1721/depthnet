<?php

namespace App\Contracts\Agent\Orchestrator;

use App\Models\Agent;
use App\Models\AgentTask;

interface OrchestratorInterface
{
    /**
     * Main entry point — scan pending tasks and dispatch them to roles.
     * Called after planner produces tasks or after any task status change.
     *
     * @param Agent $agent
     * @return void
     */
    public function tick(Agent $agent): void;

    /**
     * Called by AgentTaskPlugin when role marks task as completed.
     * Moves task to validating or done, triggers next step.
     *
     * @param AgentTask $task
     * @param string    $result Result produced by the role
     * @return void
     */
    public function onTaskCompleted(AgentTask $task, string $result): void;

    /**
     * Called by AgentTaskPlugin when validator approves or rejects a task.
     *
     * @param AgentTask $task
     * @param bool      $approved
     * @param string    $notes    Validator feedback
     * @return void
     */
    public function onValidationResult(AgentTask $task, bool $approved, string $notes): void;

    /**
     * Called by AgentTaskPlugin when role explicitly fails a task.
     * Retries up to max_attempts, then escalates to planner.
     *
     * @param AgentTask $task
     * @param string    $reason
     * @return void
     */
    public function onTaskFailed(AgentTask $task, string $reason): void;
}

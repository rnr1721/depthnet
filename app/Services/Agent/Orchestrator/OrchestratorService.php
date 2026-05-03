<?php

namespace App\Services\Agent\Orchestrator;

use App\Contracts\Agent\AgentJobServiceFactoryInterface;
use App\Contracts\Agent\Orchestrator\OrchestratorInterface;
use App\Models\Agent;
use App\Models\AgentRole;
use App\Models\AgentTask;
use App\Models\Message;
use Psr\Log\LoggerInterface;

/**
 * OrchestratorService — deterministic task dispatcher with optional validation hooks.
 *
 * Flow:
 *   planner creates tasks via AgentTaskPlugin
 *     → tick() dispatches pending tasks to role presets
 *     → role completes → onTaskCompleted()
 *       → if validator configured: task goes to validating, validator is triggered
 *       → if no validator: task approved immediately
 *     → approved: planner notified (or auto_proceed: tick() again)
 *     → rejected: retry up to max_attempts, then escalate
 *
 * The orchestrator never uses handoff — roles report back via plugin commands,
 * not via [agent handoff]. This prevents conflicts with the existing reply-to mechanism.
 *
 * Planner is notified by writing a user-role message directly to its preset history,
 * then triggering a single thinking cycle. Models treat user-role messages as
 * authoritative external input, which produces better planner responses.
 */
class OrchestratorService implements OrchestratorInterface
{
    public function __construct(
        protected Message $messageModel,
        protected AgentJobServiceFactoryInterface $agentJobServiceFactory,
        protected AgentTask $agentTaskModel,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function tick(Agent $agent): void
    {
        // Eager-load roles so findRole() doesn't query in a loop
        $agent->loadMissing('roles.preset', 'roles.validatorPreset');

        $pendingTasks = $this->agentTaskModel->where('agent_id', $agent->id)
            ->where('status', AgentTask::STATUS_PENDING)
            ->ordered()
            ->get();

        foreach ($pendingTasks as $task) {
            $this->dispatch($agent, $task);
        }

        $this->logger->info('Orchestrator: tick completed', [
            'agent_id'      => $agent->id,
            'dispatched'    => $pendingTasks->count(),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function onTaskCompleted(AgentTask $task, string $result): void
    {
        $agent = $task->agent()->with('roles.validatorPreset', 'plannerPreset')->first();
        $role  = $this->findRole($agent, $task->assigned_role);

        $task->update(['result' => $result]);

        if ($role?->hasValidator()) {
            $this->sendToValidator($task, $agent, $role);
            return;
        }

        $this->approve($task, $agent, $role);
    }

    /**
     * @inheritDoc
     */
    public function onValidationResult(AgentTask $task, bool $approved, string $notes): void
    {
        $task->update(['validator_notes' => $notes]);

        $agent = $task->agent()->with('roles', 'plannerPreset')->first();
        $role  = $this->findRole($agent, $task->assigned_role);

        if ($approved) {
            $this->approve($task, $agent, $role);
            return;
        }

        $maxAttempts = $role?->getMaxAttempts() ?? 3;

        if ($task->getAttempts() >= $maxAttempts) {
            $this->escalate($task, $agent, "Validator rejected after {$task->attempts} attempts: {$notes}");
            return;
        }

        // Rejected but still has attempts — append feedback, back to pending
        $task->update([
            'status'      => AgentTask::STATUS_PENDING,
            'description' => trim($task->description . "\n\nValidator feedback: {$notes}"),
        ]);

        $this->logger->info('Orchestrator: task rejected by validator, will retry', [
            'task_id'  => $task->id,
            'attempts' => $task->attempts,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function onTaskFailed(AgentTask $task, string $reason): void
    {
        $agent       = $task->agent()->with('roles', 'plannerPreset')->first();
        $role        = $this->findRole($agent, $task->assigned_role);
        $maxAttempts = $role?->getMaxAttempts() ?? 3;

        if ($task->getAttempts() < $maxAttempts) {
            $task->update(['status' => AgentTask::STATUS_PENDING]);
            $this->logger->info('Orchestrator: task failed, scheduled for retry', [
                'task_id'  => $task->id,
                'attempts' => $task->attempts,
            ]);
            return;
        }

        $this->escalate($task, $agent, $reason);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Dispatch a pending task to its assigned role preset.
     * Writes a user-role message to the role's preset history and triggers thinking.
     */
    private function dispatch(Agent $agent, AgentTask $task): void
    {
        $role = $this->findRole($agent, $task->assigned_role);

        if (!$role) {
            $this->logger->warning('Orchestrator: role not found for task', [
                'agent_id'      => $agent->id,
                'task_id'       => $task->id,
                'assigned_role' => $task->assigned_role,
            ]);
            return;
        }

        $task->update([
            'status'   => AgentTask::STATUS_IN_PROGRESS,
            'attempts' => $task->attempts + 1,
        ]);

        $this->writeUserMessage(
            $role->preset_id,
            $this->buildTaskMessage($task)
        );

        $this->agentJobServiceFactory->make()->start($role->preset_id, singleMode: true);

        $this->logger->info('Orchestrator: task dispatched to role', [
            'task_id'     => $task->id,
            'role'        => $task->assigned_role,
            'preset_id'   => $role->preset_id,
            'attempt'     => $task->attempts,
        ]);
    }

    /**
     * Send completed task to validator preset for review.
     */
    private function sendToValidator(AgentTask $task, Agent $agent, AgentRole $role): void
    {
        $task->update(['status' => AgentTask::STATUS_VALIDATING]);

        $this->writeUserMessage(
            $role->validator_preset_id,
            $this->buildValidationRequest($task)
        );

        $this->agentJobServiceFactory->make()->start($role->validator_preset_id, singleMode: true);

        $this->logger->info('Orchestrator: task sent to validator', [
            'task_id'      => $task->id,
            'validator_id' => $role->validator_preset_id,
        ]);
    }

    /**
     * Approve a task — mark done, then notify planner or auto-proceed.
     */
    private function approve(AgentTask $task, Agent $agent, ?AgentRole $role): void
    {
        $task->update(['status' => AgentTask::STATUS_DONE]);

        $this->logger->info('Orchestrator: task approved', ['task_id' => $task->id]);

        if ($role?->isAutoProceed()) {
            // Skip planner, dispatch next pending task immediately
            $this->tick($agent);
            return;
        }

        $this->notifyPlanner($agent, $task, 'completed');
    }

    /**
     * Escalate a stuck/failed task to the planner.
     */
    private function escalate(AgentTask $task, Agent $agent, string $reason): void
    {
        $task->update(['status' => AgentTask::STATUS_ESCALATED]);

        $this->logger->warning('Orchestrator: task escalated to planner', [
            'task_id' => $task->id,
            'reason'  => $reason,
        ]);

        $this->notifyPlanner($agent, $task, 'escalated', $reason);
    }

    /**
     * Write a user-role message to planner's preset history and wake it up.
     *
     * We write as user role (not system, not assistant) because models treat
     * user-role messages as authoritative external input — producing better,
     * more responsive planner behavior than system messages.
     */
    private function notifyPlanner(Agent $agent, AgentTask $task, string $event, string $extra = ''): void
    {
        $lines = ["[Orchestrator] Task #{$task->id} {$event}: {$task->title}"];

        if ($task->result) {
            $lines[] = "Result: {$task->result}";
        }
        if ($task->validator_notes) {
            $lines[] = "Validator notes: {$task->validator_notes}";
        }
        if ($extra) {
            $lines[] = "Note: {$extra}";
        }

        $this->writeUserMessage($agent->planner_preset_id, implode("\n", $lines));
        $this->agentJobServiceFactory->make()->start($agent->planner_preset_id, singleMode: true);
    }

    /**
     * Write a user-role message to any preset's history.
     * Central method — all orchestrator→preset communication goes through here.
     */
    private function writeUserMessage(int $presetId, string $content): void
    {
        $this->messageModel->create([
            'role'               => 'user',
            'content'            => $content,
            'from_user_id'       => null,
            'preset_id'          => $presetId,
            'is_visible_to_user' => true,
        ]);
    }

    /**
     * Build message sent to role preset when a task is dispatched.
     */
    private function buildTaskMessage(AgentTask $task): string
    {
        $lines = ["[Task #{$task->id}] {$task->title}"];

        if ($task->description) {
            $lines[] = $task->description;
        }
        if ($task->attempts > 1) {
            $lines[] = "(Attempt {$task->attempts})";
        }

        return implode("\n", $lines);
    }

    /**
     * Build message sent to validator preset.
     */
    private function buildValidationRequest(AgentTask $task): string
    {
        return implode("\n", [
            "[Validate Task #{$task->id}] {$task->title}",
            "Result to validate:",
            $task->result ?? '(empty)',
        ]);
    }

    /**
     * Find role by code within agent (uses eager-loaded collection if available).
     */
    private function findRole(Agent $agent, ?string $code): ?AgentRole
    {
        if (!$code) {
            return null;
        }

        if ($agent->relationLoaded('roles')) {
            return $agent->roles->firstWhere('code', $code);
        }

        return $agent->roles()->where('code', $code)->first();
    }
}

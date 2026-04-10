<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Orchestrator\AgentServiceInterface;
use App\Contracts\Agent\Orchestrator\AgentTaskServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Agent\IndexAgentTaskRequest;
use App\Http\Requests\Admin\Agent\SetAgentTaskStatusRequest;
use App\Http\Requests\Admin\Agent\StoreAgentTaskRequest;
use Inertia\Inertia;

class AgentTaskController extends Controller
{
    public function __construct(
        protected AgentTaskServiceInterface $agentTaskService,
        protected AgentServiceInterface $agentService,
    ) {
    }

    public function index(IndexAgentTaskRequest $request)
    {
        // Resolve agent — use provided ID or fall back to first active agent
        $agentId = $request->getAgentId();

        if ($agentId) {
            $agent = $this->agentTaskService->findAgent($agentId);
        } else {
            $agent = $this->agentService->getFirstActiveAgent();
        }

        // No agents configured at all — redirect back with message
        if (!$agent) {
            return redirect()->route('admin.agents.index')
                ->with('error', __('No active agents found. Create an agent first.'));
        }

        // If a different agent was requested but not found — fall back to first
        if ($agentId && !$agent) {
            return redirect()->route('admin.agent-tasks.index');
        }

        $agent->loadMissing('roles');

        $agents = $this->agentService->getAgentsForSelect();

        return Inertia::render('Admin/AgentTasks/Index', [
            'agent'        => [
                'id'    => $agent->id,
                'name'  => $agent->name,
                'roles' => $agent->roles->map(fn ($r) => [
                    'id'   => $r->id,
                    'code' => $r->code,
                ])->values()->all(),
            ],
            'agents'       => $agents,
            'tasks'        => $this->agentTaskService->getStructuredTasks($agent, $request->getStatusFilter()),
            'statusFilter' => $request->getStatusFilter(),
        ]);
    }

    public function store(StoreAgentTaskRequest $request)
    {
        $agent = $this->agentTaskService->findAgent($request->getAgentId());

        if (!$agent) {
            return back()->with('error', __('Agent not found.'));
        }

        $result = $this->agentTaskService->createTask(
            $agent,
            $request->getTitle(),
            $request->getDescription(),
            $request->getAssignedRole(),
            createdByRole: 'admin',
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function setStatus(SetAgentTaskStatusRequest $request, int $taskId)
    {
        $agent = $this->agentTaskService->findAgent($request->getAgentId());

        if (!$agent) {
            return back()->with('error', __('Agent not found.'));
        }

        $result = $this->agentTaskService->setTaskStatus($agent, $taskId, $request->getStatus());

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function destroy(int $taskId)
    {
        // Task ID is global — find agent via task's agent_id
        $task = \App\Models\AgentTask::find($taskId);

        if (!$task) {
            return back()->with('error', __('Task not found.'));
        }

        $agent = $this->agentTaskService->findAgent($task->agent_id);

        if (!$agent) {
            return back()->with('error', __('Agent not found.'));
        }

        $result = $this->agentTaskService->deleteTask($agent, $taskId);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function clear(IndexAgentTaskRequest $request)
    {
        $agentId = $request->getAgentId();

        if (!$agentId) {
            return back()->with('error', __('Agent not found.'));
        }

        $agent = $this->agentTaskService->findAgent($agentId);

        if (!$agent) {
            return back()->with('error', __('Agent not found.'));
        }

        $this->agentTaskService->clearTasks($agent);

        return back()->with('success', __('All tasks cleared.'));
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Cleanup\PresetCleanupServiceInterface;
use App\Contracts\Agent\Orchestrator\AgentServiceInterface;
use App\Contracts\Auth\AuthServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Agent\IndexAgentRequest;
use App\Http\Requests\Admin\Agent\StoreAgentRequest;
use App\Http\Requests\Admin\Agent\StoreAgentRoleRequest;
use App\Http\Requests\Admin\Agent\UpdateAgentRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AgentController extends Controller
{
    public function __construct(
        protected AgentServiceInterface $agentService,
        protected AuthServiceInterface $authService,
        protected PresetCleanupServiceInterface $cleanupService,
    ) {
    }

    public function index(IndexAgentRequest $request)
    {
        return Inertia::render('Admin/Agents/Index', [
            'agents'  => $this->agentService->getStructuredAgents(),
            'presets' => $this->agentService->getPresetsForSelect(),
        ]);
    }

    public function store(StoreAgentRequest $request)
    {
        $result = $this->agentService->createAgent(
            $request->getName(),
            $request->getPlannerPresetId(),
            $request->getCode(),
            $request->getDescription(),
            $request->getIsActive(),
            $this->authService->getCurrentUserId(),
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function update(UpdateAgentRequest $request, int $id)
    {
        $agent  = $this->agentService->findAgentOrFail($id);
        $result = $this->agentService->updateAgent(
            $agent,
            $request->getName(),
            $request->getPlannerPresetId(),
            $request->getCode(),
            $request->getDescription(),
            $request->getIsActive(),
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function destroy(int $id)
    {
        $agent  = $this->agentService->findAgentOrFail($id);
        $result = $this->agentService->deleteAgent($agent);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function storeRole(StoreAgentRoleRequest $request, int $id)
    {
        $agent  = $this->agentService->findAgentOrFail($id);
        $result = $this->agentService->addRole(
            $agent,
            $request->getCode(),
            $request->getPresetId(),
            $request->getValidatorPresetId(),
            $request->getMaxAttempts(),
            $request->getAutoProceed(),
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function updateRole(StoreAgentRoleRequest $request, int $id, int $roleId)
    {
        $agent  = $this->agentService->findAgentOrFail($id);
        $result = $this->agentService->updateRole(
            $agent,
            $roleId,
            $request->getCode(),
            $request->getPresetId(),
            $request->getValidatorPresetId(),
            $request->getMaxAttempts(),
            $request->getAutoProceed(),
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function destroyRole(int $id, int $roleId)
    {
        $agent  = $this->agentService->findAgentOrFail($id);
        $result = $this->agentService->deleteRole($agent, $roleId);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Clear data for all presets belonging to this agent.
     * Accepts same options as ChatController::clearHistory.
     */
    public function clear(Request $request, int $id)
    {
        $agent   = $this->agentService->findAgentOrFail($id);
        $results = $this->cleanupService->clearAgent($agent, $request->all());

        if (empty($results)) {
            return back()->with('error', __('Nothing selected to clear.'));
        }

        $count = array_sum(array_map('count', $results));

        return back()->with('success', __("Cleared :count items across :presets presets.", [
            'count'   => $count,
            'presets' => count($results),
        ]));
    }

}

<?php

namespace App\Services\Agent\Orchestrator;

use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Orchestrator\AgentServiceInterface;
use App\Models\Agent;
use App\Models\AgentRole;
use Psr\Log\LoggerInterface;

/**
 * AgentService — manages agent and role lifecycle.
 *
 * All Eloquent access is centralised here.
 * Controllers and other consumers never touch models directly.
 */
class AgentService implements AgentServiceInterface
{
    public function __construct(
        protected PresetRegistryInterface $presetRegistry,
        protected Agent $agentModel,
        protected AgentRole $agentRoleModel,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getStructuredAgents(): array
    {
        return $this->agentModel->with(['plannerPreset', 'roles.preset', 'roles.validatorPreset'])
            ->orderBy('name')
            ->get()
            ->map(fn ($agent) => $this->formatAgent($agent))
            ->all();
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
    public function findAgentOrFail(int $agentId): Agent
    {
        return $this->agentModel->findOrFail($agentId);
    }

    /**
     * @inheritDoc
     */
    public function createAgent(
        string $name,
        int $plannerPresetId,
        ?string $code = null,
        ?string $description = null,
        bool $isActive = true,
        ?int $createdBy = null
    ): array {
        try {
            $name = trim($name);
            if (empty($name)) {
                return ['success' => false, 'message' => 'Error: Agent name cannot be empty.'];
            }

            if ($code && $this->agentModel->where('code', $code)->exists()) {
                return ['success' => false, 'message' => "Error: Agent code '{$code}' is already taken."];
            }

            $agent = $this->agentModel->create([
                'name'              => $name,
                'description'       => $description ? trim($description) : null,
                'code'              => $code ? trim($code) : null,
                'planner_preset_id' => $plannerPresetId,
                'is_active'         => $isActive,
                'created_by'        => $createdBy,
            ]);

            return ['success' => true, 'message' => "Agent '{$name}' created.", 'agent' => $agent];

        } catch (\Throwable $e) {
            $this->logger->error('AgentService::createAgent error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error creating agent: ' . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function updateAgent(
        Agent $agent,
        string $name,
        int $plannerPresetId,
        ?string $code = null,
        ?string $description = null,
        bool $isActive = true
    ): array {
        try {
            $name = trim($name);
            if (empty($name)) {
                return ['success' => false, 'message' => 'Error: Agent name cannot be empty.'];
            }

            if ($code) {
                $duplicate = $this->agentModel->where('code', $code)
                    ->where('id', '!=', $agent->id)
                    ->exists();

                if ($duplicate) {
                    return ['success' => false, 'message' => "Error: Agent code '{$code}' is already taken."];
                }
            }

            $agent->update([
                'name'              => $name,
                'description'       => $description ? trim($description) : null,
                'code'              => $code ? trim($code) : null,
                'planner_preset_id' => $plannerPresetId,
                'is_active'         => $isActive,
            ]);

            return ['success' => true, 'message' => "Agent '{$name}' updated."];

        } catch (\Throwable $e) {
            $this->logger->error('AgentService::updateAgent error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating agent: ' . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteAgent(Agent $agent): array
    {
        try {
            $name = $agent->name;
            $agent->delete(); // cascades to agent_roles and agent_tasks via FK

            return ['success' => true, 'message' => "Agent '{$name}' deleted."];

        } catch (\Throwable $e) {
            $this->logger->error('AgentService::deleteAgent error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting agent: ' . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function addRole(
        Agent $agent,
        string $code,
        int $presetId,
        ?int $validatorPresetId = null,
        int $maxAttempts = 3,
        bool $autoProceed = false
    ): array {
        try {
            $code = trim($code);
            if (empty($code)) {
                return ['success' => false, 'message' => 'Error: Role code cannot be empty.'];
            }

            if ($agent->roles()->where('code', $code)->exists()) {
                return ['success' => false, 'message' => "Error: Role '{$code}' already exists for this agent."];
            }

            $role = $this->agentRoleModel->create([
                'agent_id'            => $agent->id,
                'code'                => $code,
                'preset_id'           => $presetId,
                'validator_preset_id' => $validatorPresetId,
                'max_attempts'        => $maxAttempts,
                'auto_proceed'        => $autoProceed,
            ]);

            return ['success' => true, 'message' => "Role '{$code}' added.", 'role' => $role];

        } catch (\Throwable $e) {
            $this->logger->error('AgentService::addRole error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error adding role: ' . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function updateRole(
        Agent $agent,
        int $roleId,
        string $code,
        int $presetId,
        ?int $validatorPresetId = null,
        int $maxAttempts = 3,
        bool $autoProceed = false
    ): array {
        try {
            $role = $this->agentRoleModel->where('agent_id', $agent->id)->find($roleId);
            if (!$role) {
                return ['success' => false, 'message' => "Error: Role #{$roleId} not found."];
            }

            $code = trim($code);

            $duplicate = $this->agentRoleModel->where('agent_id', $agent->id)
                ->where('code', $code)
                ->where('id', '!=', $roleId)
                ->exists();

            if ($duplicate) {
                return ['success' => false, 'message' => "Error: Role code '{$code}' already exists for this agent."];
            }

            $role->update([
                'code'                => $code,
                'preset_id'           => $presetId,
                'validator_preset_id' => $validatorPresetId,
                'max_attempts'        => $maxAttempts,
                'auto_proceed'        => $autoProceed,
            ]);

            return ['success' => true, 'message' => "Role '{$code}' updated."];

        } catch (\Throwable $e) {
            $this->logger->error('AgentService::updateRole error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating role: ' . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteRole(Agent $agent, int $roleId): array
    {
        try {
            $role = $this->agentRoleModel->where('agent_id', $agent->id)->find($roleId);
            if (!$role) {
                return ['success' => false, 'message' => "Error: Role #{$roleId} not found."];
            }

            $code = $role->code;
            $role->delete();

            return ['success' => true, 'message' => "Role '{$code}' deleted."];

        } catch (\Throwable $e) {
            $this->logger->error('AgentService::deleteRole error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting role: ' . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function getPresetsForSelect(): array
    {
        return $this->presetRegistry->getActivePresets()
            ->map(fn ($preset) => [
                'id'   => $preset->id,
                'name' => $preset->name,
                'code' => $preset->preset_code,
            ])
            ->values()
            ->all();
    }

    /**
     * @inheritDoc
     */
    public function getFirstActiveAgent(): ?Agent
    {
        return $this->agentModel->where('is_active', true)->orderBy('name')->first();
    }

    /**
     * @inheritDoc
     */
    public function getAgentsForSelect(): array
    {
        return $this->agentModel->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])
            ->all();
    }

    /**
     * @inheritDoc
     *
     * Lightweight format for the chat sidebar filter.
     * TabsPanel needs: id, name, planner.id, roles[].preset.id, roles[].validator.id
     * — enough to build the preset ID set for filtering, nothing more.
     * @return array
     */
    public function getAgentsForChat(): array
    {
        return $this->agentModel->where('is_active', true)
            ->with(['plannerPreset:id', 'roles:id,agent_id,preset_id,validator_preset_id',
                    'roles.preset:id', 'roles.validatorPreset:id'])
            ->orderBy('name')
            ->get()
            ->map(fn ($agent) => [
                'id'      => $agent->id,
                'name'    => $agent->name,
                'planner' => ['id' => $agent->plannerPreset->id],
                'roles'   => $agent->roles->map(fn ($role) => [
                    'preset'    => ['id' => $role->preset->id],
                    'validator' => $role->validatorPreset ? ['id' => $role->validatorPreset->id] : null,
                ])->values()->all(),
            ])
            ->all();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Format agent model for UI rendering.
     *
     * @param Agent $agent
     * @return array
     */
    private function formatAgent(Agent $agent): array
    {
        return [
            'id'          => $agent->id,
            'name'        => $agent->name,
            'description' => $agent->description,
            'code'        => $agent->code,
            'is_active'   => $agent->is_active,
            'planner'     => [
                'id'   => $agent->plannerPreset->id,
                'name' => $agent->plannerPreset->name,
            ],
            'roles'       => $agent->roles->map(fn ($role) => [
                'id'           => $role->id,
                'code'         => $role->code,
                'max_attempts' => $role->max_attempts,
                'auto_proceed' => $role->auto_proceed,
                'preset'       => [
                    'id'   => $role->preset->id,
                    'name' => $role->preset->name,
                ],
                'validator'    => $role->validatorPreset ? [
                    'id'   => $role->validatorPreset->id,
                    'name' => $role->validatorPreset->name,
                ] : null,
            ])->values()->all(),
        ];
    }
}

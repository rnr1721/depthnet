<?php

namespace App\Contracts\Agent\Orchestrator;

use App\Models\Agent;

interface AgentServiceInterface
{
    /**
     * Get all agents with relations loaded for UI rendering.
     *
     * @return array<int, array>
     */
    public function getStructuredAgents(): array;

    /**
     * Find active agent by ID. Returns null if not found or inactive.
     */
    public function findAgent(int $agentId): ?Agent;

    /**
     * Find agent by ID regardless of active status (for edit/delete).
     */
    public function findAgentOrFail(int $agentId): Agent;

    /**
     * Create a new agent.
     *
     * @return array{success: bool, message: string, agent?: Agent}
     */
    public function createAgent(
        string $name,
        int $plannerPresetId,
        ?string $code = null,
        ?string $description = null,
        bool $isActive = true,
        ?int $createdBy = null
    ): array;

    /**
     * Update an existing agent.
     *
     * @return array{success: bool, message: string}
     */
    public function updateAgent(
        Agent $agent,
        string $name,
        int $plannerPresetId,
        ?string $code = null,
        ?string $description = null,
        bool $isActive = true
    ): array;

    /**
     * Delete an agent and all its tasks/roles.
     *
     * @return array{success: bool, message: string}
     */
    public function deleteAgent(Agent $agent): array;

    /**
     * Add a role to an agent.
     *
     * @return array{success: bool, message: string, role?: AgentRole}
     */
    public function addRole(
        Agent $agent,
        string $code,
        int $presetId,
        ?int $validatorPresetId = null,
        int $maxAttempts = 3,
        bool $autoProceed = false
    ): array;

    /**
     * Update an existing role.
     *
     * @return array{success: bool, message: string}
     */
    public function updateRole(
        Agent $agent,
        int $roleId,
        string $code,
        int $presetId,
        ?int $validatorPresetId = null,
        int $maxAttempts = 3,
        bool $autoProceed = false
    ): array;

    /**
     * Delete a role by ID scoped to agent.
     *
     * @return array{success: bool, message: string}
     */
    public function deleteRole(Agent $agent, int $roleId): array;

    /**
     * Get presets formatted for select inputs in UI.
     *
     * @return array<int, array>
     */
    public function getPresetsForSelect(): array;

    /**
     * Get first active agent. Used as fallback when no agent_id is provided.
     *
     * @return Agent|null
     */
    public function getFirstActiveAgent(): ?Agent;

    /**
     * Get agents formatted for agent selector in task UI.
     *
     * @return array<int, array>
     */
    public function getAgentsForSelect(): array;

    /**
     * Get agents formatted for the chat sidebar filter.
     * Lightweight — only id, name, planner id and role preset ids.
     * No unnecessary data sent to the frontend.
     *
     * @return array<int, array>
     */
    public function getAgentsForChat(): array;
}

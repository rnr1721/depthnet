<?php

namespace App\Contracts\Agent;

interface AgentJobServiceFactoryInterface
{
    /**
     * Resolve AgentJobService from the container.
     *
     * Used wherever AgentJobService cannot be injected directly due to
     * circular dependency chains (AgentActionsHandler, AgentMessageService,
     * AgentPlugin, etc.). The factory itself is safe to inject eagerly —
     * it only resolves the service on first call.
     */
    public function make(): AgentJobServiceInterface;
}

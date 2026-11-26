<?php

namespace App\Services\Agent\Status;

use App\Contracts\Agent\Status\AgentStatusServiceInterface;

/**
 * Drop-in helpers for classes deep inside the cycle.
 *
 * Resolved lazily from the container (like SkillLoadService in RagPipelineService)
 * so no constructor signatures change — important for classes built by factories.
 * If the service isn't bound, everything degrades to "just run $fn".
 */
trait TracksAgentStatus
{
    protected function trackStatus(string $phase, ?string $label, callable $fn): mixed
    {
        $status = $this->agentStatus();

        return $status ? $status->track($phase, $label, $fn) : $fn();
    }

    protected function annotateStatus(string $label): void
    {
        $this->agentStatus()?->annotate($label);
    }

    protected function agentStatus(): ?AgentStatusServiceInterface
    {
        try {
            return app(AgentStatusServiceInterface::class);
        } catch (\Throwable) {
            return null;
        }
    }
}

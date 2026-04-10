<?php

namespace App\Contracts\Agent\Orchestrator;

interface OrchestratorFactoryInterface
{
    /**
     * Create an instance of the Orchestrator.
     *
     * @return OrchestratorInterface
     */
    public function make(): OrchestratorInterface;
}

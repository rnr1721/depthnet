<?php

namespace App\Services\Agent\Orchestrator;

use App\Contracts\Agent\Orchestrator\OrchestratorFactoryInterface;
use App\Contracts\Agent\Orchestrator\OrchestratorInterface;
use Illuminate\Contracts\Container\Container;

class OrchestratorFactory implements OrchestratorFactoryInterface
{
    public function __construct(
        protected Container $container
    ) {
    }

    public function make(): OrchestratorInterface
    {
        return $this->container->make(OrchestratorInterface::class);
    }
}

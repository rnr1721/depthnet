<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentJobServiceFactoryInterface;
use App\Contracts\Agent\AgentJobServiceInterface;
use Illuminate\Contracts\Container\Container;

class AgentJobServiceFactory implements AgentJobServiceFactoryInterface
{
    public function __construct(
        protected Container $container
    ) {
    }

    /**
     * @inheritDoc
     */
    public function make(): AgentJobServiceInterface
    {
        return $this->container->make(AgentJobServiceInterface::class);
    }
}

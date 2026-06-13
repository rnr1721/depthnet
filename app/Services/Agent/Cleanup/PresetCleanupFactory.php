<?php

namespace App\Services\Agent\Cleanup;

use App\Contracts\Agent\Cleanup\PresetCleanupFactoryInterface;
use App\Contracts\Agent\Cleanup\PresetCleanupServiceInterface;
use Illuminate\Contracts\Container\Container;

class PresetCleanupFactory implements PresetCleanupFactoryInterface
{
    public function __construct(
        protected Container $container
    ) {
    }

    public function make(): PresetCleanupServiceInterface
    {
        return $this->container->make(PresetCleanupServiceInterface::class);
    }
}

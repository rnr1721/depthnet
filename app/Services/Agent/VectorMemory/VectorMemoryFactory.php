<?php

namespace App\Services\Agent\VectorMemory;

use App\Contracts\Agent\VectorMemory\VectorMemoryFactoryInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryServiceInterface;

/**
 * Concrete factory for VectorMemory service instances.
 *
 * Both services are injected via the service container so they share
 * all their own dependencies (TfIdfService, Logger, etc.) without
 * the factory needing to know about them.
 *
 * Register in AiServiceProvider:
 *
 *   $this->app->singleton(VectorMemoryFactoryInterface::class, function ($app) {
 *       return new VectorMemoryFactory(
 *           $app->make(VectorMemoryService::class),
 *           $app->make(VectorMemoryAssociativeService::class),
 *       );
 *   });
 */
class VectorMemoryFactory implements VectorMemoryFactoryInterface
{
    public function __construct(
        protected VectorMemoryService            $defaultService,
        protected VectorMemoryAssociativeService $associativeService,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function make(string $driver = self::DRIVER_DEFAULT): VectorMemoryServiceInterface
    {
        return match ($driver) {
            self::DRIVER_DEFAULT     => $this->defaultService,
            self::DRIVER_ASSOCIATIVE => $this->associativeService,
            default => throw new \InvalidArgumentException(
                "Unknown VectorMemory driver: '{$driver}'. " .
                "Valid drivers: '" . self::DRIVER_DEFAULT . "', '" . self::DRIVER_ASSOCIATIVE . "'."
            ),
        };
    }
}

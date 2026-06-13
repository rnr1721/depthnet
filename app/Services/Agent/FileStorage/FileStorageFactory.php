<?php

namespace App\Services\Agent\FileStorage;

use App\Contracts\Agent\FileStorage\FileStorageFactoryInterface;
use App\Contracts\Agent\FileStorage\FileStorageServiceInterface;

/**
 * Resolves the correct FileStorageService for a given driver identifier.
 *
 * Register in AiServiceProvider:
 *
 *   $this->app->singleton(FileStorageFactoryInterface::class, function ($app) {
 *       return new FileStorageFactory(
 *           $app->make(LaravelFileStorageService::class),
 *           $app->make(SandboxFileStorageService::class),
 *       );
 *   });
 *
 * Adding a new driver: implement FileStorageServiceInterface, inject here,
 * add to the $drivers map — nothing else changes.
 */
class FileStorageFactory implements FileStorageFactoryInterface
{
    /** @var array<string, FileStorageServiceInterface> */
    private array $drivers;

    public function __construct(FileStorageServiceInterface ...$services)
    {
        foreach ($services as $service) {
            $this->drivers[$service->getDriver()] = $service;
        }
    }

    /** @inheritDoc */
    public function make(string $driver): FileStorageServiceInterface
    {
        if (!isset($this->drivers[$driver])) {
            throw new \InvalidArgumentException(
                "FileStorageFactory: unknown driver '{$driver}'. " .
                "Available: " . implode(', ', array_keys($this->drivers))
            );
        }

        return $this->drivers[$driver];
    }

    /** @inheritDoc */
    public function available(): array
    {
        return array_keys($this->drivers);
    }
}

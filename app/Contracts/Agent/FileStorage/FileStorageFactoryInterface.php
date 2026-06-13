<?php

namespace App\Contracts\Agent\FileStorage;

/**
 * Factory contract for resolving file storage drivers.
 */
interface FileStorageFactoryInterface
{
    /**
     * Resolve a storage service by driver name.
     *
     * @param  string  $driver  laravel|sandbox|...
     * @throws \InvalidArgumentException for unknown drivers
     */
    public function make(string $driver): FileStorageServiceInterface;

    /**
     * List all registered driver identifiers.
     *
     * @return string[]
     */
    public function available(): array;
}

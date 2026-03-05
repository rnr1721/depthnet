<?php

namespace App\Contracts\Agent\VectorMemory;

/**
 * Factory interface for creating VectorMemory service instances.
 *
 * Allows the application to request either a standard TF-IDF service
 * or the associative chain-traversal variant without depending on
 * concrete implementations.
 *
 * Usage:
 *   $factory->make()              // → VectorMemoryService (default, for plugin commands)
 *   $factory->make('associative') // → VectorMemoryAssociativeService (for RAG enrichment)
 */
interface VectorMemoryFactoryInterface
{
    /**
     * Default (standard TF-IDF) driver identifier.
     */
    public const DRIVER_DEFAULT     = 'default';

    /**
     * Associative chain-traversal driver identifier.
     */
    public const DRIVER_ASSOCIATIVE = 'associative';

    /**
     * Create (or return a cached) VectorMemory service instance.
     *
     * @param string $driver One of the DRIVER_* constants.
     *                       Defaults to DRIVER_DEFAULT.
     * @return VectorMemoryServiceInterface
     *
     * @throws \InvalidArgumentException When an unknown driver is requested.
     */
    public function make(string $driver = self::DRIVER_DEFAULT): VectorMemoryServiceInterface;
}

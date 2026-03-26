<?php

namespace App\Services\Agent\VectorMemory;

use App\Contracts\Agent\VectorMemory\VectorMemoryFactoryInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryServiceInterface;

/**
 * Concrete factory for VectorMemory service instances.
 *
 * Resolves the two-dimensional (mode × engine) combination to the correct
 * service class. All four services are injected via the container so they
 * carry their own dependencies without the factory knowing about them.
 *
 * Register in AiServiceProvider:
 *
 *   $this->app->singleton(VectorMemoryFactoryInterface::class, function ($app) {
 *       return new VectorMemoryFactory(
 *           $app->make(VectorMemoryService::class),
 *           $app->make(VectorMemoryAssociativeService::class),
 *           $app->make(EmbeddingVectorMemoryService::class),
 *           $app->make(EmbeddingAssociativeVectorMemoryService::class),
 *       );
 *   });
 *
 */
class VectorMemoryFactory implements VectorMemoryFactoryInterface
{
    public function __construct(
        protected VectorMemoryService                     $flatTfidf,
        protected VectorMemoryAssociativeService          $associativeTfidf,
        protected EmbeddingVectorMemoryService            $flatEmbedding,
        protected EmbeddingAssociativeVectorMemoryService $associativeEmbedding,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function make(
        string $mode   = self::MODE_FLAT,
        string $engine = self::ENGINE_TFIDF,
    ): VectorMemoryServiceInterface {

        return match (true) {
            $mode === self::MODE_FLAT        && $engine === self::ENGINE_TFIDF     => $this->flatTfidf,
            $mode === self::MODE_ASSOCIATIVE && $engine === self::ENGINE_TFIDF     => $this->associativeTfidf,
            $mode === self::MODE_FLAT        && $engine === self::ENGINE_EMBEDDING => $this->flatEmbedding,
            $mode === self::MODE_ASSOCIATIVE && $engine === self::ENGINE_EMBEDDING => $this->associativeEmbedding,
            default => throw new \InvalidArgumentException(
                "Unknown VectorMemory combination: mode='{$mode}', engine='{$engine}'. " .
                "Valid modes: flat, associative. Valid engines: tfidf, embedding."
            ),
        };
    }

}

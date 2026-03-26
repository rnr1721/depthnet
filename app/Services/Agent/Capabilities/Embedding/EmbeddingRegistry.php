<?php

namespace App\Services\Agent\Capabilities\Embedding;

use App\Contracts\Agent\Capabilities\CapabilityProviderInterface;
use App\Services\Agent\Capabilities\AbstractCapabilityRegistry;
use App\Services\Agent\Capabilities\Embedding\Drivers\NovitaEmbeddingProvider;

/**
 * Registry for embedding capability providers.
 *
 * Register new drivers here as they become available.
 * The registry is populated in EmbeddingServiceProvider::boot().
 */
class EmbeddingRegistry extends AbstractCapabilityRegistry
{
    protected function getCapabilityType(): string
    {
        return 'embedding';
    }

    /**
     * Instantiate the correct provider class for the given driver name.
     * Add a new match arm when adding a new embedding driver.
     *
     * @param  string               $driverName
     * @param  array<string, mixed> $config
     */
    protected function instantiate(string $driverName, array $config): CapabilityProviderInterface
    {
        return match ($driverName) {
            'novita' => new NovitaEmbeddingProvider($this->http, $this->logger, $config),
            // 'openai' => new OpenAiEmbeddingProvider($this->http, $this->logger, $config),
            // 'cohere' => new CohereEmbeddingProvider($this->http, $this->logger, $config),
            default  => throw new \InvalidArgumentException(
                "No instantiation logic for embedding driver '{$driverName}'."
            ),
        };
    }
}

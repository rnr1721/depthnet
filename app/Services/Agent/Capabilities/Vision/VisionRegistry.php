<?php

namespace App\Services\Agent\Capabilities\Vision;

use App\Contracts\Agent\Capabilities\CapabilityProviderInterface;
use App\Services\Agent\Capabilities\AbstractCapabilityRegistry;
use App\Services\Agent\Capabilities\Vision\Drivers\ClaudeVisionProvider;
use App\Services\Agent\Capabilities\Vision\Drivers\DeepSeekVisionProvider;
use App\Services\Agent\Capabilities\Vision\Drivers\NovitaVisionProvider;

/**
 * Registry for vision capability providers.
 *
 * Register new drivers here as they become available.
 * Populated in AiServiceProvider (same place EmbeddingRegistry is built).
 */
class VisionRegistry extends AbstractCapabilityRegistry
{
    protected function getCapabilityType(): string
    {
        return 'vision';
    }

    /**
     * Instantiate the correct provider class for the given driver name.
     * Add a new match arm when adding a new vision driver.
     *
     * @param  string               $driverName
     * @param  array<string, mixed> $config
     */
    protected function instantiate(string $driverName, array $config): CapabilityProviderInterface
    {
        return match ($driverName) {
            'novita' => new NovitaVisionProvider($this->http, $this->logger, $config),
            'claude'   => new ClaudeVisionProvider($this->http, $this->logger, $config),
            default    => throw new \InvalidArgumentException(
                "No instantiation logic for vision driver '{$driverName}'."
            ),
        };
    }
}

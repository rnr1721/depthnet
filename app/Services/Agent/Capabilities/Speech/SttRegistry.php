<?php

namespace App\Services\Agent\Capabilities\Speech;

use App\Contracts\Agent\Capabilities\CapabilityProviderInterface;
use App\Services\Agent\Capabilities\AbstractCapabilityRegistry;
use App\Services\Agent\Capabilities\Speech\Drivers\BrowserSttProvider;
use App\Services\Agent\Capabilities\Speech\Drivers\OpenAiCompatibleSttProvider;

/**
 * Registry for speech-to-text providers.
 *
 * Add a match arm when adding a driver. Populated in AiServiceProvider next to
 * VisionRegistry and EmbeddingRegistry.
 */
class SttRegistry extends AbstractCapabilityRegistry
{
    protected function getCapabilityType(): string
    {
        return 'stt';
    }

    /**
     * @param  string               $driverName
     * @param  array<string, mixed> $config
     */
    protected function instantiate(string $driverName, array $config): CapabilityProviderInterface
    {
        return match ($driverName) {
            'browser' => new BrowserSttProvider($config),
            'openai_compatible' => new OpenAiCompatibleSttProvider(
                $this->http,
                $this->logger,
                $config
            ),
            default => throw new \InvalidArgumentException(
                "No instantiation logic for STT driver '{$driverName}'."
            ),
        };
    }
}

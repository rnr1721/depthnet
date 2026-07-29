<?php

namespace App\Services\Agent\Capabilities\Speech;

use App\Contracts\Agent\Capabilities\CapabilityProviderInterface;
use App\Services\Agent\Capabilities\AbstractCapabilityRegistry;
use App\Services\Agent\Capabilities\Speech\Drivers\BrowserTtsProvider;
use App\Services\Agent\Capabilities\Speech\Drivers\OpenAiCompatibleTtsProvider;

/**
 * Registry for text-to-speech providers.
 */
class TtsRegistry extends AbstractCapabilityRegistry
{
    protected function getCapabilityType(): string
    {
        return 'tts';
    }

    /**
     * @param  string               $driverName
     * @param  array<string, mixed> $config
     */
    protected function instantiate(string $driverName, array $config): CapabilityProviderInterface
    {
        return match ($driverName) {
            'browser' => new BrowserTtsProvider($config),
            'openai_compatible' => new OpenAiCompatibleTtsProvider(
                $this->http,
                $this->logger,
                $config
            ),
            default => throw new \InvalidArgumentException(
                "No instantiation logic for TTS driver '{$driverName}'."
            ),
        };
    }
}

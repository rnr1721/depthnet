<?php

namespace App\Services\Agent\Capabilities\Messenger;

use App\Contracts\Agent\Capabilities\MessengerProviderInterface;
use App\Contracts\Agent\Capabilities\MessengerServiceInterface;
use App\Models\AiPreset;
use App\Models\PresetCapabilityConfig;
use App\Services\Agent\Capabilities\Messenger\DTO\InboundSettings;
use Psr\Log\LoggerInterface;

/**
 * High-level messenger service. Thin on purpose: providers carry the
 * operations; this resolves which provider (if any) a preset has, and its
 * inbound policy.
 */
class MessengerService implements MessengerServiceInterface
{
    public function __construct(
        protected MessengerRegistry $registry,
        protected LoggerInterface $logger,
    ) {
    }

    public function provider(AiPreset $preset): ?MessengerProviderInterface
    {
        try {
            /** @var MessengerProviderInterface */
            return $this->registry->makeForPreset($preset);
        } catch (\Throwable $e) {
            $this->logger->debug('MessengerService: provider unavailable — ' . $e->getMessage(), [
                'preset_id' => $preset->id,
            ]);
            return null;
        }
    }

    public function isAvailable(AiPreset $preset): bool
    {
        return $this->registry->isAvailableForPreset($preset);
    }

    public function inboundSettings(AiPreset $preset): InboundSettings
    {
        $config = PresetCapabilityConfig::forPreset($preset->id)
            ->forCapability(MessengerProviderInterface::CAPABILITY)
            ->active()
            ->first();

        if ($config === null || !$this->registry->has($config->driver)) {
            return InboundSettings::off();
        }

        return InboundSettings::fromConfig($config->config ?? []);
    }
}

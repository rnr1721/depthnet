<?php

namespace App\Services\Agent\Capabilities\Vision;

use App\Contracts\Agent\Capabilities\VisionProviderInterface;
use App\Contracts\Agent\Capabilities\VisionServiceInterface;
use App\Models\AiPreset;
use App\Models\PresetCapabilityConfig;
use App\Services\Agent\Capabilities\Vision\DTO\ImageData;
use Psr\Log\LoggerInterface;

/**
 * High-level vision service.
 *
 * Resolves the preset's configured provider via VisionRegistry and delegates
 * describe(). Swallows provider/registry failures and returns null so callers
 * degrade gracefully.
 *
 * No caching (see VisionServiceInterface docblock).
 */
class VisionService implements VisionServiceInterface
{
    public function __construct(
        protected VisionRegistry $registry,
        protected LoggerInterface $logger,
    ) {
    }

    public function describe(ImageData $image, ?string $query, AiPreset $preset): ?string
    {
        $provider = $this->resolveProvider($preset);
        if ($provider === null) {
            return null;
        }

        try {
            return $provider->describe($image, $query);
        } catch (\Throwable $e) {
            $this->logger->error('VisionService: describe failed — ' . $e->getMessage(), [
                'preset_id' => $preset->id,
                'driver'    => $provider->getDriverName(),
            ]);
            return null;
        }
    }

    public function isAvailable(AiPreset $preset): bool
    {
        return $this->registry->isAvailableForPreset($preset);
    }

    /**
     * Read the 'send_to_pool' flag from the preset's active vision config.
     * Defaults to false when no config exists or the flag is unset.
     */
    public function shouldSendToPool(AiPreset $preset): bool
    {
        $config = PresetCapabilityConfig::forPreset($preset->id)
            ->forCapability(VisionProviderInterface::CAPABILITY)
            ->active()
            ->first();

        if ($config === null) {
            return false;
        }

        return (bool) ($config->config['send_to_pool'] ?? false);
    }

    private function resolveProvider(AiPreset $preset): ?VisionProviderInterface
    {
        try {
            /** @var VisionProviderInterface */
            return $this->registry->makeForPreset($preset);
        } catch (\Throwable $e) {
            $this->logger->debug('VisionService: provider unavailable — ' . $e->getMessage(), [
                'preset_id' => $preset->id,
            ]);
            return null;
        }
    }
}

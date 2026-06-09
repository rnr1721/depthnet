<?php

namespace App\Services\Agent\Capabilities\Vision;

use App\Contracts\Agent\Capabilities\VisionProviderInterface;
use App\Contracts\Agent\Capabilities\VisionServiceInterface;
use App\Models\AiPreset;
use App\Models\PresetCapabilityConfig;
use App\Services\Agent\Capabilities\Vision\DTO\ImageData;
use App\Services\Agent\Capabilities\Vision\DTO\VisionResult;
use Psr\Log\LoggerInterface;

/**
 * High-level vision service.
 *
 * Resolves the preset's provider, normalizes the image per the preset's config,
 * and delegates to the provider. Exposes two shapes:
 *
 *   describeResult() : VisionResult — success/text/error, for callers that want
 *                      to surface WHY vision failed (documents, MCP, GUI test).
 *   describe()       : ?string      — legacy facade (text or null) for callers
 *                      that don't care about the reason.
 *
 * Normalization happens here, so all entry points share resizing/format/quality.
 * No caching.
 */
class VisionService implements VisionServiceInterface
{
    public function __construct(
        protected VisionRegistry $registry,
        protected ImageNormalizer $normalizer,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Structured describe — carries a human-readable reason on failure.
     */
    public function describeResult(ImageData $image, ?string $query, AiPreset $preset): VisionResult
    {
        $provider = $this->resolveProvider($preset);
        if ($provider === null) {
            return VisionResult::fail(
                'Vision capability is not configured or not active for this preset.'
            );
        }

        $image = $this->normalizer->normalize($image, $this->normalizationOptions($preset));

        try {
            $result = $provider->describeResult($image, $query);
        } catch (\Throwable $e) {
            // Providers shouldn't throw for ordinary failures, but guard anyway.
            $this->logger->error('VisionService: provider threw — ' . $e->getMessage(), [
                'preset_id' => $preset->id,
                'driver'    => $provider->getDriverName(),
            ]);
            return VisionResult::fail('Vision provider error: ' . $e->getMessage());
        }

        if (!$result->success) {
            // Log the reason too, so logs and UI agree.
            $this->logger->warning('VisionService: describe failed — ' . $result->error, [
                'preset_id' => $preset->id,
                'driver'    => $provider->getDriverName(),
            ]);
        }

        return $result;
    }

    /**
     * Legacy facade: text or null. Built on describeResult().
     */
    public function describe(ImageData $image, ?string $query, AiPreset $preset): ?string
    {
        return $this->describeResult($image, $query, $preset)->textOrNull();
    }

    public function isAvailable(AiPreset $preset): bool
    {
        return $this->registry->isAvailableForPreset($preset);
    }

    public function shouldSendToPool(AiPreset $preset): bool
    {
        $config = $this->activeConfig($preset);
        return $config !== null && (bool) ($config->config['send_to_pool'] ?? false);
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function normalizationOptions(AiPreset $preset): array
    {
        $config = $this->activeConfig($preset)?->config ?? [];

        return [
            'max_width'  => $config['norm_max_width']  ?? null,
            'max_height' => $config['norm_max_height'] ?? null,
            'mode'       => $config['norm_mode']        ?? null,
            'format'     => $config['norm_format']      ?? null,
            'quality'    => $config['norm_quality']     ?? null,
        ];
    }

    private function activeConfig(AiPreset $preset): ?PresetCapabilityConfig
    {
        return PresetCapabilityConfig::forPreset($preset->id)
            ->forCapability(VisionProviderInterface::CAPABILITY)
            ->active()
            ->first();
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

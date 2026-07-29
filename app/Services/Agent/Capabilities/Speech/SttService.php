<?php

namespace App\Services\Agent\Capabilities\Speech;

use App\Contracts\Agent\Capabilities\SttProviderInterface;
use App\Contracts\Agent\Capabilities\SttServiceInterface;
use App\Contracts\Agent\Capabilities\TranscribesAudioInterface;
use App\Models\AiPreset;
use App\Models\PresetCapabilityConfig;
use App\Services\Agent\Capabilities\Speech\DTO\AudioData;
use App\Services\Agent\Capabilities\Speech\DTO\SttResult;
use Psr\Log\LoggerInterface;

/**
 * High-level speech-to-text service.
 *
 * Built on VisionService, with one addition it did not need: the client/server
 * distinction. Vision providers all run on the backend, so "configured" and
 * "callable" meant the same thing. STT breaks that — a preset can have a fully
 * valid browser provider that the backend simply cannot invoke.
 *
 * Rather than let that surface as a TypeError deep in a Telegram handler, this
 * service checks for TranscribesAudioInterface and returns a failure explaining
 * exactly what to change.
 */
class SttService implements SttServiceInterface
{
    /** Guard against pathological uploads before we spend CPU on them. */
    private const MAX_AUDIO_BYTES = 25 * 1024 * 1024;

    public function __construct(
        protected SttRegistry $registry,
        protected LoggerInterface $logger,
    ) {
    }

    public function transcribeResult(
        AudioData $audio,
        AiPreset $preset,
        ?string $language = null,
    ): SttResult {
        if ($audio->isEmpty()) {
            return SttResult::fail('No audio was received.');
        }

        if ($audio->sizeBytes() > self::MAX_AUDIO_BYTES) {
            $mb = round($audio->sizeBytes() / 1048576, 1);
            return SttResult::fail(
                "Recording is {$mb} MB, which exceeds the 25 MB limit."
            );
        }

        $provider = $this->resolveProvider($preset);
        if ($provider === null) {
            return SttResult::fail(
                'Speech recognition is not configured or not active for this preset.'
            );
        }

        if (!$provider instanceof TranscribesAudioInterface) {
            return SttResult::fail(sprintf(
                "The '%s' speech provider runs in the browser and cannot transcribe on "
                . "the server. Configure a server-side STT driver for this preset to "
                . "accept voice from other channels.",
                $provider->getDisplayName()
            ));
        }

        try {
            $result = $provider->transcribe($audio, $language);
        } catch (\Throwable $e) {
            $this->logger->error('SttService: provider threw — ' . $e->getMessage(), [
                'preset_id' => $preset->id,
                'driver'    => $provider->getDriverName(),
            ]);
            return SttResult::fail('Speech recognition error: ' . $e->getMessage());
        }

        if (!$result->success) {
            $this->logger->warning('SttService: transcription failed — ' . $result->error, [
                'preset_id' => $preset->id,
                'driver'    => $provider->getDriverName(),
                'source'    => $audio->getSourceLabel(),
            ]);
        }

        return $result;
    }

    public function transcribe(
        AudioData $audio,
        AiPreset $preset,
        ?string $language = null,
    ): ?string {
        return $this->transcribeResult($audio, $preset, $language)->textOrNull();
    }

    public function isAvailable(AiPreset $preset): bool
    {
        return $this->registry->isAvailableForPreset($preset);
    }

    public function isServerSideAvailable(AiPreset $preset): bool
    {
        return $this->resolveProvider($preset) instanceof TranscribesAudioInterface;
    }

    public function getClientConfig(AiPreset $preset): ?array
    {
        $provider = $this->resolveProvider($preset);

        return $provider?->getClientConfig();
    }

    public function shouldSendToPool(AiPreset $preset): bool
    {
        $config = $this->activeConfig($preset);

        return $config !== null && (bool) ($config->config['send_to_pool'] ?? false);
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function activeConfig(AiPreset $preset): ?PresetCapabilityConfig
    {
        return PresetCapabilityConfig::forPreset($preset->id)
            ->forCapability(SttProviderInterface::CAPABILITY)
            ->active()
            ->first();
    }

    private function resolveProvider(AiPreset $preset): ?SttProviderInterface
    {
        try {
            /** @var SttProviderInterface */
            return $this->registry->makeForPreset($preset);
        } catch (\Throwable $e) {
            $this->logger->debug('SttService: provider unavailable — ' . $e->getMessage(), [
                'preset_id' => $preset->id,
            ]);
            return null;
        }
    }
}

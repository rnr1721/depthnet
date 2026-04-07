<?php

namespace App\Services\Integrations\Rhasspy;

use App\Contracts\Integrations\Rhasspy\RhasspyClientInterface;
use App\Contracts\Integrations\Rhasspy\RhasspyServiceInterface;
use App\Models\AiPreset;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

class RhasspyService implements RhasspyServiceInterface
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function speakForPreset(string $text, AiPreset $preset): void
    {
        // Everything in try/catch — Rhasspy problems must never affect the agent
        try {
            if (!$this->isEnabledForPreset($preset)) {
                return;
            }

            $client = $this->makeClientForPreset($preset);
            $voice  = $preset->getRhasspyTtsVoice() ?? '';

            $success = $client->say($text, $voice);

            if (!$success) {
                $this->logger->warning('RhasspyService: speak failed for preset', [
                    'preset_id'   => $preset->getId(),
                    'preset_name' => $preset->getName(),
                    'text_length' => mb_strlen($text),
                ]);
            }

        } catch (\Throwable $e) {
            $this->logger->error('RhasspyService: unexpected error in speakForPreset', [
                'preset_id' => $preset->getId(),
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * @inheritDoc
     */
    public function isEnabledForPreset(AiPreset $preset): bool
    {
        if (!$preset->getRhasspyEnabled()) {
            return false;
        }

        $url = $preset->getRhasspyUrl();
        if (empty($url)) {
            $this->logger->warning('RhasspyService: enabled but URL is empty', [
                'preset_id' => $preset->getId(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function validateIncomingToken(AiPreset $preset, string $token): bool
    {
        $stored = $preset->getRhasspyIncomingToken();

        if (empty($stored) || empty($token)) {
            return false;
        }

        return hash_equals($stored, $token);
    }

    /**
     * @inheritDoc
     */
    public function isIncomingEnabledForPreset(AiPreset $preset): bool
    {
        return $preset->getRhasspyIncomingEnabled()
            && !empty($preset->getRhasspyUrl())
            && !empty($preset->getRhasspyIncomingToken());
    }

    /**
     * @inheritDoc
     */
    public function makeClientForPreset(AiPreset $preset): RhasspyClientInterface
    {
        return new RhasspyClient(
            $this->http,
            $this->logger,
            $preset->getRhasspyUrl(),
        );
    }
}

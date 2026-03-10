<?php

namespace App\Services\Agent\Voice\DTO;

use App\Contracts\Agent\Voice\InnerVoiceResponseInterface;
use App\Models\AiPreset;

class InnerVoiceDTO implements InnerVoiceResponseInterface
{
    public function __construct(
        private AiPreset $mainPreset,
        private ?AiPreset $voicePreset = null,
        private ?string $response = null,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getResponse(): ?string
    {
        return $this->response;
    }

    /**
     * @inheritDoc
     */
    public function getVoicePreset(): ?AiPreset
    {
        return $this->voicePreset;
    }

    /**
     * @inheritDoc
     */
    public function getMainPreset(): AiPreset
    {
        return $this->mainPreset;
    }
}

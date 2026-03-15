<?php

namespace App\Services\Agent\Enricher;

use App\Contracts\Agent\Enricher\EnricherResponseInterface;
use App\Models\AiPreset;

class EnricherResponse implements EnricherResponseInterface
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
    public function getPreset(): ?AiPreset
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

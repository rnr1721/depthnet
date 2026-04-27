<?php

namespace App\Services\Agent\Enricher;

use App\Contracts\Agent\Enricher\EnricherResponseInterface;
use App\Models\AiPreset;

class EnricherResponse implements EnricherResponseInterface
{
    /**
     * @param AiPreset            $mainPreset   Main preset being enriched
     * @param AiPreset|null       $voicePreset  Secondary preset (RAG, voice, etc.)
     * @param string|null         $response     Text ready for shortcode injection
     * @param array<string, true> $retrievedIds Namespaced IDs of retrieved records
     *                                          for cross-config deduplication
     */
    public function __construct(
        private AiPreset  $mainPreset,
        private ?AiPreset $voicePreset = null,
        private ?string   $response = null,
        private array     $retrievedIds = [],
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

    /**
     * @inheritDoc
     */
    public function getRetrievedIds(): array
    {
        return $this->retrievedIds;
    }
}

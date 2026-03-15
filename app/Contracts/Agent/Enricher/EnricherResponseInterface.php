<?php

namespace App\Contracts\Agent\Enricher;

use App\Models\AiPreset;

interface EnricherResponseInterface
{
    /**
     * Enricher response
     *
     * @return string|null
     */
    public function getResponse(): ?string;

    /**
    * Enricher preset
    *
    * @return AiPreset|null
    */
    public function getPreset(): ?AiPreset;

    /**
     * Main preset
     *
     * @return AiPreset
     */
    public function getMainPreset(): AiPreset;
}

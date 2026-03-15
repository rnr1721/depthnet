<?php

namespace App\Contracts\Agent\Enricher;

use App\Models\AiPreset;

interface ContextEnricherInterface extends EnricherInterface
{
    /**
     * Get voice preset for main preset
     *
     * @param AiPreset $mainPreset
     * @param string $target
     * @return AiPreset|null
     */
    public function getVoicePreset(AiPreset $mainPreset, string $target): ?AiPreset;
}

<?php

namespace App\Contracts\Agent\Enricher;

use App\Models\AiPreset;
use App\Models\PresetInnerVoiceConfig;

interface InnerVoiceEnricherInterface
{
    /**
     * Call a single voice preset and return its response as a formatted string.
     *
     * Returns null if the voice preset is unavailable, inactive, or errors out.
     * Errors must never propagate — the main agent cycle must not crash.
     *
     * @param  AiPreset                $mainPreset  The preset being enriched
     * @param  array                   $context     Current conversation messages
     * @param  PresetInnerVoiceConfig  $config      The voice config to run
     * @return string|null
     */
    public function enrich(AiPreset $mainPreset, array $context, PresetInnerVoiceConfig $config): ?string;
}

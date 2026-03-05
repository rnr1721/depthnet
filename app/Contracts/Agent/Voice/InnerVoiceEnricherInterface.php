<?php

namespace App\Contracts\Agent\Voice;

use App\Models\AiPreset;

interface InnerVoiceEnricherInterface
{
    /**
     * Generate an inner voice message for the given preset and context.
     *
     * Returns a formatted string to be injected via [[inner_voice]]
     * placeholder, or null if voice is not configured or call failed.
     *
     * @param AiPreset $preset  The main preset being enriched
     * @param array    $context Current conversation context
     * @return string|null
     */
    public function enrich(AiPreset $preset, array $context): ?string;
}

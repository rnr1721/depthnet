<?php

namespace App\Contracts\Agent\Enricher;

use App\Models\AiPreset;

interface CyclePromptEnricherInterface
{
    /**
     * Inject an anti-loop impulse into the input pool for the current cycle.
     *
     * Returns the raw response string from the cycle prompt preset,
     * or null if the preset is not configured / unavailable.
     *
     * Side-effects: adds an entry to InputPoolService.
     * Must never throw — errors are caught and logged internally.
     *
     * @param  AiPreset $preset  The main preset running the cycle
     * @param  array    $context Current conversation messages
     * @return string|null
     */
    public function enrich(AiPreset $preset, array $context): ?string;

    /**
     * Resolve the voice preset configured for cycle prompting.
     *
     * @param  AiPreset $preset
     * @return AiPreset|null
     */
    public function getVoicePreset(AiPreset $preset): ?AiPreset;
}

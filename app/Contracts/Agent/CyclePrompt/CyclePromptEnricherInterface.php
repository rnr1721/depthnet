<?php

namespace App\Contracts\Agent\CyclePrompt;

use App\Models\AiPreset;

interface CyclePromptEnricherInterface
{
    /**
     * Generate a cycle continuation prompt using the configured cycle_prompt_preset.
     * Returns null if no preset is configured, preset is inactive, or generation fails.
     * When null is returned, CycleContextBuilder falls back to the static instruction.
     *
     * @param AiPreset $preset  The main preset currently thinking
     * @param array    $context Current conversation context
     * @return string|null
     */
    public function enrich(AiPreset $preset, array $context): ?string;
}

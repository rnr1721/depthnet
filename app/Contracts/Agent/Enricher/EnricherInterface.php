<?php

namespace App\Contracts\Agent\Enricher;

use App\Models\AiPreset;

interface EnricherInterface
{
    /**
     * Generate an message for the given preset and context.
     *
     * Returns a DTO
     *
     * @param AiPreset $preset  The main preset being enriched
     * @param array    $context Current conversation context
     * @param string   $target single or cycle
     * @param string|null $target
     * @return EnricherResponseInterface
     */
    public function enrich(AiPreset $preset, array $context, ?string $target = null): EnricherResponseInterface;
}

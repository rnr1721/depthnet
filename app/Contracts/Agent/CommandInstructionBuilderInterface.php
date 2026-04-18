<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;

interface CommandInstructionBuilderInterface
{
    /**
     * Instructions for model, how use the plugins
     *
     * @param AiPreset $preset
     * @return string
     */
    public function buildInstructions(AiPreset $preset): string;
}

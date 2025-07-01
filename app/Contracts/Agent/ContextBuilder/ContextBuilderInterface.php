<?php

namespace App\Contracts\Agent\ContextBuilder;

use App\Models\AiPreset;

interface ContextBuilderInterface
{
    /**
     * Build context array from messages
     *
     * @param AiPreset $preset
     * @return array
     */
    public function build(AiPreset $preset): array;
}

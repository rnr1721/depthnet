<?php

namespace App\Contracts\Agent\ContextBuilder;

use App\Models\AiPreset;

interface ContextBuilderInterface
{
    /**
     * Build context array from messages
     *
     * @param AiPreset $preset
     * @param int|null $maxContextLimit
     * @return array
     */
    public function build(AiPreset $preset, ?int $maxContextLimit = null): array;
}

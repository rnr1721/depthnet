<?php

namespace App\Contracts\Agent\ContextBuilder;

use App\Models\AiPreset;

interface ContextBuilderInterface
{
    /**
     * Build context array from messages
     *
     * @param AiPreset $preset Preset for context
     * @param AiPreset $sourcePreset Preset for RAG, Inner voice etc
     * @param int|null $maxContextLimit
     * @return array
     */
    public function build(AiPreset $preset, ?AiPreset $sourcePreset = null, ?int $maxContextLimit = null): array;
}

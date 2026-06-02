<?php

namespace App\Services\Agent\ContextBuilder\Traits;

use App\Models\AiPreset;

trait ContextRagTrait
{
    private function resolveSourceRagPreset(AiPreset $preset): AiPreset
    {
        $targetId = $preset->getTargetPresetId();
        if (!$targetId) {
            return $preset;
        }

        return AiPreset::find($targetId) ?? $preset;
    }
}

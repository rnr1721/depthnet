<?php

namespace App\Services\Agent\Traits;

use App\Models\AiPreset;

trait ResolvesSourcePresetTrait
{
    private function resolveSourcePreset(AiPreset $preset): AiPreset
    {
        $targetId = $preset->getTargetPresetId();
        if (!$targetId) {
            return $preset;
        }

        return AiPreset::find($targetId) ?? $preset;
    }
}

<?php

namespace App\Services\Agent\Plugins;

use App\Models\AiPreset;

trait PluginPresetTrait
{
    protected AiPreset $preset;


    public function setCurrentPreset(AiPreset $preset): void
    {
        $this->preset = $preset;
    }
}

<?php

namespace App\Services\Agent\Plugins\Traits;

use App\Models\AiPreset;

trait PluginPresetTrait
{
    protected AiPreset $preset;

    /**
     * Set current active preset for plugin
     *
     * @param AiPreset $preset
     * @return void
     */
    public function setCurrentPreset(AiPreset $preset): void
    {
        $this->preset = $preset;
    }

}

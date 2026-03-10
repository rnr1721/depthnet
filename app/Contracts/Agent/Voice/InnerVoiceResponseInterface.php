<?php

namespace App\Contracts\Agent\Voice;

use App\Models\AiPreset;

interface InnerVoiceResponseInterface
{
    /**
     * Inner voice response
     *
     * @return string|null
     */
    public function getResponse(): ?string;

    /**
    * Inner Voice preset
    *
    * @return AiPreset|null
    */
    public function getVoicePreset(): ?AiPreset;

    /**
     * Main preset
     *
     * @return AiPreset
     */
    public function getMainPreset(): AiPreset;
}

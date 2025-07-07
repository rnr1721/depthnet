<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;

interface AgentActionsInterface
{
    /**
     * Process AI response and execute actions
     * - commands etc
     *
     * @param string $responseString Model response string
     * @param AiPreset $preset
     * @param boolean $isUser Command run user, not model
     * @return AiActionsResponseInterface
     */
    public function runActions(
        string $responseString,
        AiPreset $preset,
        bool $isUser = false
    ): AiActionsResponseInterface;
}

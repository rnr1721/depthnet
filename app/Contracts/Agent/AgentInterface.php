<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;

/**
 * Interface AgentInterface
 * This interface defines the contract for the agent's "thinking" process.
 *
 * @package App\Contracts\Agent
 */
interface AgentInterface
{
    /**
     * Start the agent's "thinking" process
     *
     * @param AiPreset $currentPreset Current working preset
     * @param AiPreset|null $Main pipeline preset - Default null
     * @return AiAgentResponseInterface
     */
    public function think(
        AiPreset $currentPreset,
        ?AiPreset $mainPreset = null
    ): AiAgentResponseInterface;

}

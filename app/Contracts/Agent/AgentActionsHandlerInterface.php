<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;

/**
 * AgentActionsHandlerInterdace
 *
 * New interface to handle agent actions responses and errors.
 * This allows us to separate the concerns of executing actions
 * from handling their outcomes, making the code cleaner and more
 * maintainable.
 */
interface AgentActionsHandlerInterface
{
    /**
     * Handle successful or error response
     *
     * @param mixed $response
     * @param AiPreset $preset
     * @param AiPreset|null $mainPreset
     * @return AiAgentResponseInterface
     */
    public function handleResponse(
        $response,
        AiPreset $preset,
        ?AiPreset $mainPreset = null
    ): AiAgentResponseInterface;

    /**
     * Handle errors during thinking process
     *
     * @param \Exception $e
     * @param int $presetId
     * @return AiAgentResponseInterface
     */
    public function handleError(\Exception $e, int $presetId): AiAgentResponseInterface;
}

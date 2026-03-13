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
    public const CACHE_KEY = 'agent:results:preset:%d';

    /**
     * Handle successful or error response
     *
     * @param mixed $response
     * @param AiPreset $preset
     * @return AiAgentResponseInterface
     */
    public function handleResponse(
        $response,
        AiPreset $preset
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

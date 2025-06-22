<?php

namespace App\Contracts\Agent;

use App\Models\Message;

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
     * @return Message|null
     */
    public function think(): ?Message;

}

<?php

namespace App\Contracts\Agent;

interface AgentActionsInterface
{
    /**
     * Process AI response and execute actions
     * - commands etc
     *
     * @param string $responseString Model response string
     * @param boolean $isUser Command run user, not model
     * @return AiActionsResponseInterface
     */
    public function runActions(
        string $responseString,
        bool $isUser = false
    ): AiActionsResponseInterface;
}

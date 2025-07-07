<?php

namespace App\Contracts\Agent;

interface AiActionsResponseInterface
{
    /**
     * Result from Actions
     *
     * @return string
     */
    public function getResult(): string;

    /**
     * Role correction from actions
     * is message field
     *
     * @return string
     */
    public function getRole(): string;

    /**
     * Message will be visible for user
     *
     * @return boolean
     */
    public function isVisibleForUser(): bool;

    /**
     * Additional system message
     *
     * @return string|null
     */
    public function getSystemMessage(): ?string;

    /**
     * Get handoff actions
     *
     * @return array|null
     */
    public function getHandoff(): ?array;
}

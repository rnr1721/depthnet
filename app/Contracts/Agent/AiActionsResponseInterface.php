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
     * Actions contains commands to execute
     *
     * @return boolean
     */
    public function hasCommands(): bool;

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

    /**
     * Return a copy of this response with additional text appended to the result.
     *
     * Allows AgentActions to attach lint error output or other supplementary
     * text to an already-built response without mutating the original instance
     * or exposing a setter. All fields other than result are preserved as-is.
     *
     * @param  string                   $extra Text to append to the existing result
     * @return AiActionsResponseInterface       New instance with appended result
     */
    public function withAppendedResult(string $extra): AiActionsResponseInterface;

    /**
     * Get the individual CommandResult objects from this cycle.
     *
     * Used by AgentActionsHandler::buildToolResults() in tool_calls mode
     * to build a per-tool result array with exact tool_call_id mapping.
     * Each CommandResult carries the toolCallId set by ToolCallParser.
     *
     * Returns an empty array in tag mode (CommandResults are not needed
     * downstream in that path).
     *
     * @return \App\Services\Agent\Plugins\DTO\CommandResult[]
     */
    public function getCommandResults(): array;

    /**
     * If is one turn (if model ask for one additional turn)
     *
     * @return boolean
     */
    public function hasTurn(): bool;
}

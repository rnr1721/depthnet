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

    /**
     * Whether any plugin executed this cycle declared itself as requiring
     * procedural continuity (needsLongContext). Read by the work-mode detector.
     *
     * @return boolean
     */
    public function containedLongContextPlugin(): bool;

    /**
     * Whether a task was created this cycle (planner productive signal).
     *
     * Set when AgentTaskPlugin::execute successfully creates a task. Read by
     * AgentActionsHandler::determineTurnNeed to reset the planner stall counter
     * — creating tasks is productive and must not count toward a stall. Does not
     * by itself stop the planner's self-continue loop.
     *
     * @return boolean
     */
    public function createdTask(): bool;

    /**
     * Whether the planner committed its planning round this cycle.
     *
     * Set when AgentTaskPlugin::commit runs. Read by determineTurnNeed as the
     * planner's terminal signal — stops the self-continue loop so the planner
     * goes idle until the orchestrator wakes it with a result.
     *
     * @return boolean
     */
    public function plannerCommitted(): bool;

    /**
     * Skill numbers the model asked to LOAD this cycle, space-joined (e.g. "3 7").
     * Empty when none.
     */
    public function skillLoad(): string;

    /**
     * Skill numbers the model asked to UNLOAD this cycle, space-joined.
     * Empty when none.
     */
    public function skillUnload(): string;

}

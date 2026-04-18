<?php

namespace App\Services\Agent\DTO;

use App\Contracts\Agent\AiActionsResponseInterface;

/**
 * Immutable DTO carrying the result of a single agent actions cycle.
 *
 * Produced by AgentActions::runActions() after parsing and executing
 * all commands found in the model response. Consumed by AgentActionsHandler
 * to persist messages and route inter-agent communication.
 *
 * Fields:
 *   result          — formatted command execution output (system_command_results block)
 *   role            — message role for database persistence: 'thinking', 'command', 'user'
 *   hasCommands     — true if at least one command was executed this cycle
 *   isVisibleForUser — true if the result should be shown in the chat UI
 *   systemMessage   — optional text to surface as a visible system message (e.g. speak output)
 *   handoff         — optional inter-agent routing data extracted from AgentPlugin
 */
class ActionsResponseDTO implements AiActionsResponseInterface
{
    public function __construct(
        private string $result = '',
        private string $role = '',
        private bool $hasCommands = false,
        private bool $isVisibleForUser = false,
        private ?string $systemMessage = null,
        private ?array $handoff = null,
        private array $commandResults = [],
    ) {
    }

    /**
     * Return a copy of this DTO with additional text appended to the result.
     *
     * @param  string              $extra
     * @return AiActionsResponseInterface
     */
    public function withAppendedResult(string $extra): AiActionsResponseInterface
    {
        return new self(
            $this->result . $extra,
            $this->role,
            $this->hasCommands,
            $this->isVisibleForUser,
            $this->systemMessage,
            $this->handoff,
            $this->commandResults,
        );
    }

    /**
     * Get individual CommandResult objects from this cycle.
     *
     * @return \App\Services\Agent\Plugins\DTO\CommandResult[]
     */
    public function getCommandResults(): array
    {
        return $this->commandResults;
    }

    /**
     * Get the formatted command execution output.
     *
     * Contains the system_command_results block with success/error lines
     * for each executed command. Empty string if no commands were executed.
     *
     * @return string
     */
    public function getResult(): string
    {
        return $this->result;
    }

    /**
     * Get the message role for database persistence.
     *
     * Possible values:
     *   'thinking' — model output with no commands
     *   'command'  — model output that triggered at least one command
     *   'user'     — message originating from a user action
     *   'system'   — error or system-generated message
     *
     * @return string
     */
    public function getRole(): string
    {
        return $this->role;
    }

    /**
     * Whether at least one command was executed during this cycle.
     *
     * @return bool
     */
    public function hasCommands(): bool
    {
        return $this->hasCommands;
    }

    /**
     * Whether the result should be visible in the chat UI.
     *
     * True when a plugin sets the 'speak' execution meta key,
     * indicating that its output should surface as a system message.
     *
     * @return bool
     */
    public function isVisibleForUser(): bool
    {
        return $this->isVisibleForUser;
    }

    /**
     * Get the optional system message text to display in the chat.
     *
     * Set when a plugin (e.g. Mood, TTS) writes to the 'speak' execution
     * meta key. AgentActionsHandler creates a visible system message from
     * this value and fires an AgentSpeakEvent.
     *
     * @return string|null
     */
    public function getSystemMessage(): ?string
    {
        return $this->systemMessage;
    }

    /**
     * Get the optional inter-agent handoff routing data.
     *
     * Set by AgentPlugin when the model writes [agent handoff]target:msg[/agent].
     * Structure: ['target_preset' => string, 'handoff_message' => string|null]
     * Consumed by AgentActionsHandler::deliverInterAgentMessages().
     *
     * @return array|null
     */
    public function getHandoff(): ?array
    {
        return $this->handoff;
    }
}

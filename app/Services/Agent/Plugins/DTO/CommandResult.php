<?php

namespace App\Services\Agent\Plugins\DTO;

/**
 * Result of executing a single ParsedCommand through a plugin.
 *
 * Produced by CommandExecutor::executeCommand() and collected into
 * CommandExecutionResult. The toolCallId is propagated from ParsedCommand
 * so AgentActionsHandler can match each result to its provider-issued
 * tool_call id when persisting tool_calls mode messages.
 *
 * Fields:
 *   command       — the original ParsedCommand that was executed
 *   result        — plugin output string (empty on error)
 *   success       — whether the plugin executed without errors
 *   error         — error message if success=false, null otherwise
 *   executionMeta — plugin-side metadata: speak, handoff, and other signals
 *   toolCallId    — provider tool_call id copied from command->toolCallId.
 *                   Null for tag-mode commands (no id exists in that case).
 */
class CommandResult
{
    public readonly ?string $toolCallId;

    public function __construct(
        public readonly ParsedCommand $command,
        public readonly string $result,
        public readonly bool $success,
        public readonly ?string $error = null,
        public readonly array $executionMeta = [],
    ) {
        // Propagate tool_call id from the originating command so callers
        // don't need to reach into $command themselves
        $this->toolCallId = $command->toolCallId;
    }
}

<?php

namespace App\Services\Agent\Plugins\DTO;

/**
 * Represents a single parsed plugin command ready for execution.
 *
 * Produced by CommandParserSmart (tag mode) or ToolCallParser (tool_calls mode)
 * and consumed by CommandExecutor. Both parsers produce identical ParsedCommand
 * objects so CommandExecutor has no awareness of which parsing path was used.
 *
 * Fields:
 *   plugin     — plugin name, e.g. 'memory', 'journal', 'run'
 *   method     — method to invoke, e.g. 'show', 'search', 'execute' (default)
 *   content    — command payload: raw text, code, or JSON arguments
 *   position   — byte offset in the original response (tag mode) or
 *                synthetic index*1000 (tool_calls mode); used for ordering only
 *   toolCallId — provider-issued tool_call id, present only in tool_calls mode.
 *                Set by ToolCallParser from the model response and propagated
 *                through CommandResult so AgentActionsHandler can store it in
 *                metadata for correct tool_result turn reconstruction.
 */
class ParsedCommand
{
    public function __construct(
        public readonly string $plugin,
        public readonly string $method,
        public readonly string $content,
        public readonly int $position,
        public readonly ?string $toolCallId = null,
    ) {
    }
}

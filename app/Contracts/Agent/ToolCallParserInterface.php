<?php

namespace App\Contracts\Agent;

/**
 * Parses native tool_calls from an AI model response into ParsedCommand objects.
 *
 * This is the tool_calls-mode counterpart to CommandParserInterface.
 * Both interfaces return the same ParsedCommand[] output, so CommandExecutor
 * does not need to know which parsing path was used.
 *
 * Supported input formats:
 *   - Anthropic:  JSON array of content blocks with type=tool_use
 *   - OpenAI / DeepSeek / compatible: {"tool_calls":[...]} or bare array
 *   - Single call: {"name":"..","input":{..}}
 *
 * Mapping convention:
 *   tool.name        → ParsedCommand::plugin
 *   tool.input.method  → ParsedCommand::method  (default: 'execute')
 *   tool.input.content → ParsedCommand::content (default: '')
 *   If input has no 'content' key, the remaining input fields are
 *   JSON-encoded into content so structured plugins (e.g. MCP) can parse them.
 */
interface ToolCallParserInterface extends CommandParserInterface
{
    // Inherits parse(string $output): ParsedCommand[] from CommandParserInterface.
    // No additional methods required — the interface exists to allow
    // type-safe injection and separate binding in the service container.
}

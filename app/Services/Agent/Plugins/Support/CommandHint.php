<?php

namespace App\Services\Agent\Plugins\Support;

use App\Models\AiPreset;

/**
 * CommandHint — renders "how to call this" in the syntax the model uses NOW.
 *
 * Results and errors often point the model to its next action ("check the
 * screen", "refresh the map"). Written in tag syntax, such a hint misleads a
 * tool_calls model: it may start typing [plugin method]...[/plugin] as plain
 * text, which nothing executes. Every model-facing hint inside a result, an
 * error or a placeholder goes through here.
 *
 * Not for getInstructions(): those are tag-mode by definition (the
 * [[command_instructions]] block is suppressed in tool_calls mode).
 *
 *   tags:       [terminal screen][/terminal]     [terminal]ls -la[/terminal]
 *   tool_calls: terminal(method="screen")        terminal(method="execute", content="ls -la")
 */
final class CommandHint
{
    public static function isToolCalls(AiPreset $preset): bool
    {
        return $preset->getAgentResultMode() === 'tool_calls';
    }

    public static function method(AiPreset $preset, string $plugin, string $method = 'execute', string $content = ''): string
    {
        if (self::isToolCalls($preset)) {
            return $plugin . '(method="' . $method . '"'
                . ($content !== '' ? ', content="' . $content . '"' : '')
                . ')';
        }

        $open = $method === 'execute' ? "[{$plugin}]" : "[{$plugin} {$method}]";

        return "{$open}{$content}[/{$plugin}]";
    }
}

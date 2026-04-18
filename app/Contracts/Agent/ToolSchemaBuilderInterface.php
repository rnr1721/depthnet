<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;

/**
 * Builds an OpenAI-compatible tools array from the registered plugin registry.
 *
 * Used by Agent when a preset operates in command_mode = 'tool_calls'.
 * The resulting array is passed to the AI engine via ModelRequestDTO::additionalParams['tools']
 * and forwarded to the provider API as-is (OpenAI, DeepSeek) or after format
 * conversion (Anthropic — handled inside ClaudeModel).
 *
 * Each tool entry follows the OpenAI function-calling schema:
 * <code>
 * [
 *   "type" => "function",
 *   "function" => [
 *     "name"        => "plugin_name",
 *     "description" => "...",
 *     "parameters"  => [
 *       "type"       => "object",
 *       "properties" => [...],
 *       "required"   => [...],
 *     ],
 *   ],
 * ]
 * </code>
 *
 * Plugins that need a precise schema should implement getToolSchema(): array
 * returning the inner "function" object. Plugins that don't implement it
 * receive a default schema with generic method and content fields.
 */
interface ToolSchemaBuilderInterface
{
    /**
     * Build a tools array for all enabled plugins of the given preset.
     *
     * Disabled plugins are excluded. Plugins that implement getToolSchema()
     * override the default schema generation.
     *
     * @param  AiPreset $preset
     * @return array    OpenAI-compatible tools array
     */
    public function buildForPreset(AiPreset $preset): array;
}

<?php

namespace App\Services\Agent;

use App\Contracts\Agent\ToolSchemaBuilderInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Models\AiPreset;

/**
 * Builds an OpenAI-compatible tools array from the registered plugin registry.
 *
 * Used by Agent::generateResponse() when a preset operates in command_mode='tool_calls'.
 * The resulting array is placed into ModelRequestDTO::additionalParams['tools'] and
 * forwarded to the provider API. OpenAI-compatible engines (DeepSeek, Fireworks, etc.)
 * use it as-is; Anthropic-based engines convert it internally (see ClaudeModel).
 *
 * Schema generation has two levels:
 *
 * 1. **Plugin-defined schema** — if a plugin implements getToolSchema(): array,
 *    its return value is used as the inner "function" object. This allows plugins
 *    with complex argument structures (e.g. McpPlugin with dynamic server tools)
 *    to provide precise, discoverable schemas.
 *
 * 2. **Default schema** — for plugins that do not implement getToolSchema(),
 *    a generic schema with two fields is generated:
 *      - method:  string (optional enum built from getInstructions() if available)
 *      - content: string
 *    This mirrors the tag-syntax contract and works for all standard plugins.
 *
 * Output format (OpenAI function-calling spec):
 * <code>
 * [
 *   [
 *     "type" => "function",
 *     "function" => [
 *       "name"        => "memory",
 *       "description" => "Persistent notepad...",
 *       "parameters"  => [
 *         "type"       => "object",
 *         "properties" => ["method" => [...], "content" => [...]],
 *         "required"   => [],
 *       ],
 *     ],
 *   ],
 *   ...
 * ]
 * </code>
 *
 * @implements ToolSchemaBuilderInterface
 */
class ToolSchemaBuilder implements ToolSchemaBuilderInterface
{
    public function __construct(
        protected PluginRegistryInterface $pluginRegistry
    ) {
    }

    /**
     * Build a tools array for all enabled plugins of the given preset.
     *
     * Iterates over all plugins currently registered in the registry.
     * Disabled plugins are silently skipped — they should not appear
     * in the tools list sent to the model.
     *
     * @param  AiPreset $preset Current preset (used for plugin enablement checks)
     * @return array            OpenAI-compatible tools array, may be empty
     */
    public function buildForPreset(AiPreset $preset): array
    {
        $tools = [];

        foreach ($this->pluginRegistry->all() as $plugin) {
            // Skip plugins that are turned off for this preset
            if (!$plugin->isEnabled()) {
                continue;
            }

            $schema = $this->getPluginSchema($plugin);
            if ($schema !== null) {
                $tools[] = $schema;
            }
        }

        return $tools;
    }

    /**
     * Resolve the tool schema for a single plugin.
     *
     * Checks whether the plugin implements getToolSchema(). If it does and
     * returns a non-empty array, that is used as the "function" descriptor.
     * Otherwise falls back to buildDefaultSchema().
     *
     * @param  object     $plugin Any object implementing CommandPluginInterface
     * @return array|null         Full OpenAI tool entry, or null to skip this plugin
     */
    protected function getPluginSchema(object $plugin): ?array
    {
        // Plugin can override with a precise custom schema
        if (method_exists($plugin, 'getToolSchema')) {
            $custom = $plugin->getToolSchema();
            if (!empty($custom)) {
                return [
                    'type'     => 'function',
                    'function' => $custom,
                ];
            }
        }

        return $this->buildDefaultSchema($plugin);
    }

    /**
     * Build a generic tool schema for plugins that do not define their own.
     *
     * The schema exposes two parameters:
     *   - method:  optional string, enum-constrained if methods can be inferred
     *              from the plugin's getInstructions() output
     *   - content: optional string, carries the command payload
     *
     * This mirrors the tag-syntax contract:
     *   [plugin method]content[/plugin]
     *
     * @param  object $plugin
     * @return array  Full OpenAI tool entry
     */
    protected function buildDefaultSchema(object $plugin): array
    {
        $name        = $plugin->getName();
        $description = $plugin->getDescription();
        $methodEnum  = $this->extractMethodsFromInstructions($plugin);

        $properties = [
            'content' => [
                'type'        => 'string',
                'description' => 'Content or arguments for the command',
            ],
        ];

        // Add method field — with enum if we could infer available methods,
        // or with a generic description if instructions are not parseable
        if (!empty($methodEnum)) {
            $properties['method'] = [
                'type'        => 'string',
                'description' => 'Action to perform',
                'enum'        => $methodEnum,
            ];
        } else {
            $properties['method'] = [
                'type'        => 'string',
                'description' => 'Action to perform (e.g. execute, show, search, list)',
            ];
        }

        return [
            'type'     => 'function',
            'function' => [
                'name'        => $name,
                'description' => $description,
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => $properties,
                    'required'   => [],
                ],
            ],
        ];
    }

    /**
     * Attempt to extract an enum of available methods from plugin instructions.
     *
     * Scans each instruction string for the pattern [plugin_name method],
     * collects unique method names, and returns them as an enum array.
     *
     * Example instruction: "Show memory contents: [memory show][/memory]"
     * Extracted method: "show"
     *
     * Returns an empty array if the plugin has no getInstructions() method
     * or if no method patterns are found.
     *
     * @param  object   $plugin
     * @return string[] Unique method names found in instructions
     */
    protected function extractMethodsFromInstructions(object $plugin): array
    {
        if (!method_exists($plugin, 'getInstructions')) {
            return [];
        }

        $name    = $plugin->getName();
        $methods = [];

        foreach ($plugin->getInstructions() as $instruction) {
            // Match [plugin_name method] pattern
            if (preg_match('/\[' . preg_quote($name) . '\s+([a-z][a-z0-9_]*)\]/i', $instruction, $m)) {
                $methods[] = $m[1];
            }
        }

        return array_values(array_unique($methods));
    }
}

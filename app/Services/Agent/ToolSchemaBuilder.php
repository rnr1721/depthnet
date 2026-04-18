<?php

namespace App\Services\Agent;

use App\Contracts\Agent\PluginManagerFactoryInterface;
use App\Contracts\Agent\ToolSchemaBuilderInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Models\AiPreset;

/**
 * Builds an OpenAI-compatible tools array for a preset's enabled plugins.
 *
 * Plugin-provided getToolSchema($config) takes precedence. If it returns
 * an empty array, we auto-generate a default schema from the plugin's
 * description + methods inferred from instructions. All three metadata
 * methods receive the per-preset config.
 */
class ToolSchemaBuilder implements ToolSchemaBuilderInterface
{
    public function __construct(
        protected PluginRegistryInterface $pluginRegistry,
        protected PluginManagerFactoryInterface $pluginManagerFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function buildForPreset(AiPreset $preset): array
    {
        $tools = [];

        $entries = $this->pluginManagerFactory->get()
            ->getEnabledPluginsWithContextsForPreset($preset);

        foreach ($entries as $entry) {
            $schema = $this->getPluginSchema($entry['plugin'], $entry['context']->config);
            if ($schema !== null) {
                $tools[] = $schema;
            }
        }

        return $tools;
    }

    /**
     * Prefer plugin-defined schema; fall back to auto-generated.
     *
     * @param array<string, mixed> $config
     */
    protected function getPluginSchema(object $plugin, array $config): ?array
    {
        $custom = $plugin->getToolSchema($config);
        if (!empty($custom)) {
            return [
                'type'     => 'function',
                'function' => $custom,
            ];
        }

        return $this->buildDefaultSchema($plugin, $config);
    }

    /**
     * Build a generic tool schema from description + inferred methods.
     *
     * @param array<string, mixed> $config
     */
    protected function buildDefaultSchema(object $plugin, array $config): array
    {
        $name        = $plugin->getName();
        $description = $plugin->getDescription($config);
        $methodEnum  = $this->extractMethodsFromInstructions($plugin, $config);

        $properties = [
            'content' => [
                'type'        => 'string',
                'description' => 'Content or arguments for the command',
            ],
        ];

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
     * Extract an enum of methods by parsing the plugin's instructions for
     * the [name method] pattern.
     *
     * @param  array<string, mixed> $config
     * @return string[]
     */
    protected function extractMethodsFromInstructions(object $plugin, array $config): array
    {
        $instructions = $plugin->getInstructions($config);
        if (empty($instructions)) {
            return [];
        }

        $name    = $plugin->getName();
        $methods = [];

        foreach ($instructions as $instruction) {
            if (preg_match('/\[' . preg_quote($name) . '\s+([a-z][a-z0-9_]*)\]/i', $instruction, $m)) {
                $methods[] = $m[1];
            }
        }

        return array_values(array_unique($methods));
    }
}

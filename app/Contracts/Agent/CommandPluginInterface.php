<?php

namespace App\Contracts\Agent;

use App\Services\Agent\Plugins\DTO\PluginExecutionContext;

/**
 * Plugin interface — stateless API.
 *
 * Mental model:
 *   A plugin instance is a singleton stateless function bundle. Anything
 *   that varies between presets comes through the context. The plugin
 *   reads $context->config, $context->preset, $context->enabled — never
 *   $this->config or $this->preset.
 */
interface CommandPluginInterface
{
    /**
     * Get plugin name (used as command tag, e.g. "memory" → [memory]...[/memory])
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get plugin description for instructions
     *
     * @param array $config
     * @return string
     */
    public function getDescription(array $config = []): string;

    /**
     * Get usage instructions shown to the AI.
     *
     * Note: this method does NOT receive a context. Instructions describe
     * the plugin's general capabilities, not preset-specific behaviour.
     * If you need to vary the instructions based on preset config, use
     * getInstructionsForContext() (see optional methods below) — but most
     * plugins don't need that.
     *
     * @param array $config
     * @return array
     */
    public function getInstructions(array $config = []): array;

    /**
     * Execute the default command for this plugin.
     *
     * Receives the resolved execution context. The plugin must read its
     * config from $context->config and check $context->enabled rather than
     * any internal state.
     *
     * @param string $content
     * @param PluginExecutionContext $context
     * @return string
     */
    public function execute(string $content, PluginExecutionContext $context): string;

    /**
     * Get config field definitions for the admin UI.
     *
     * @return array
     */
    public function getConfigFields(): array;

    /**
     * Validate a config payload before persisting.
     * Return array of field => error message; empty array means valid.
     *
     * @param array $config
     * @return array
     */
    public function validateConfig(array $config): array;

    /**
     * Get default config values for this plugin.
     * Used as the seed for new PresetPluginConfig rows and as the fallback
     * when a config key is missing.
     *
     * @return array
     */
    public function getDefaultConfig(): array;

    /**
     * Whether the plugin defines a method by this name.
     * The default implementation in PluginMethodTrait inspects the class
     * methods and excludes interface boilerplate.
     *
     * @param string $method
     * @return boolean
     */
    public function hasMethod(string $method): bool;

    /**
     * Invoke a named method on the plugin with the given content and context.
     * Default implementation in PluginMethodTrait handles routing.
     *
     * @param string $method
     * @param string $content
     * @param PluginExecutionContext $context
     * @return string
     */
    public function callMethod(string $method, string $content, PluginExecutionContext $context): string;

    /**
     * List of method names callable via [plugin method]content[/plugin].
     * Used for tool schema generation and for the admin UI.
     *
     * @return array
     */
    public function getAvailableMethods(): array;

    /**
     * Optional: success message to show after a successful command.
     * Return null to use the default ("⚡ SUCCESS: ...").
     * Supports {method} placeholder.
     *
     * @return string|null
     */
    public function getCustomSuccessMessage(): ?string;

    /**
     * Optional: error message to show after a failed command.
     * Return null to use the default ("⚠️ ERROR: ...").
     * Supports {method} placeholder.
     *
     * @return string|null
     */
    public function getCustomErrorMessage(): ?string;

    /**
     * Whether two consecutive commands of this plugin can be merged into one.
     * Used by CommandParserSmart.
     *
     * @return boolean
     */
    public function canBeMerged(): bool;

    /**
     * Separator string used when merging consecutive commands.
     * Null means use the default "\n".
     *
     * @return string|null
     */
    public function getMergeSeparator(): ?string;

    /**
     * Method names that are self-closing — i.e. don't require closing tag content.
     * Example: ['pause', 'resume', 'status'] for AgentPlugin so the agent can
     * write [agent pause][/agent] without content and the preprocessor will
     * auto-close it.
     *
     * @return array
     */
    public function getSelfClosingTags(): array;

    /**
     * Side-channel data the plugin wants to bubble up after execution.
     * Used for things like the speak/handoff signals from AgentPlugin.
     * Returning the data also resets the internal buffer (one-shot).
     *
     * @return array
     */
    public function getPluginExecutionMeta(): array;

    // ── OPTIONAL METHODS (NOT in the interface) ──────────────────────────────
    //
    // These are recognised by the framework via method_exists() — implement
    // them only if your plugin needs them. They're documented here so plugin
    // authors know what hooks are available.
    //
    // public function registerShortcodes(PluginExecutionContext $context): void;
    //   Called once when a preset is applied to the registry. Use to register
    //   per-preset placeholders/shortcodes. Replaces the old pluginReady().
    //   Most plugins don't need this. AgentPlugin, RhythmPlugin, MoodPlugin,
    //   MemoryPlugin, WorkspacePlugin do.
    //
    // public function getToolSchema(): array;
    //   Custom OpenAI function-calling schema for tool_calls mode. If absent,
    //   ToolSchemaBuilder generates a generic two-arg schema (method+content).
    //   Used by AgentPlugin and McpPlugin which have argument structures
    //   richer than the generic schema captures.
}

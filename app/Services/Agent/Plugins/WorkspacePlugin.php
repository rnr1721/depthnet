<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Contracts\Agent\Workspace\WorkspaceServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * WorkspacePlugin — persistent cross-session key-value scratchpad.
 *
 * Unlike regular memory (a flat notepad) the workspace stores named,
 * independently updatable keys so the agent can maintain separate
 * "areas" of thought: drafts, plans, intermediate conclusions, etc.
 *
 * The full workspace is always available in the system prompt via the
 * [[workspace]] placeholder.
 *
 * Commands:
 *   [workspace set]key: value[/workspace]      — create or overwrite a key
 *   [workspace append]key: value[/workspace]   — append text to existing key
 *   [workspace get]key[/workspace]             — read a single key
 *   [workspace delete]key[/workspace]          — delete a single key
 *   [workspace clear][/workspace]              — wipe the entire workspace
 *   [workspace list][/workspace]               — list all keys (no values)
 */
class WorkspacePlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'workspace';

    public function __construct(
        protected WorkspaceServiceInterface $workspaceService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected LoggerInterface $logger
    ) {
        $this->initializeConfig();
    }

    // -------------------------------------------------------------------------
    // CommandPluginInterface — identity & metadata
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(): string
    {
        return 'Persistent cross-session key-value scratchpad. that survive across thinking cycles.';
    }

    public function getInstructions(): array
    {
        return [
            'Set or overwrite a key:  [workspace set]key: your content here[/workspace]',
            'Append to a key:         [workspace append]key: additional content[/workspace]',
            'Read a single key:       [workspace get]key[/workspace]',
            'Delete a single key:     [workspace delete]key[/workspace]',
            'Wipe entire workspace:   [workspace clear][/workspace]',
            'List all keys:           [workspace list][/workspace]',
        ];
    }

    /**
     * Tool schema for tool_calls mode.
     *
     * Overrides the default ToolSchemaBuilder schema to provide precise
     * parameter descriptions, especially the "key: value" content format
     * required by set/append operations.
     *
     * @return array OpenAI-compatible function descriptor (inner "function" object)
     */
    public function getToolSchema(): array
    {
        return [
            'name'        => self::PLUGIN_NAME,
            'description' => 'Persistent cross-session key-value scratchpad. '
                . 'Stores named, independently updatable keys that survive across thinking cycles. '
                . 'Use for active task state, working variables, drafts, plans.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['set', 'append', 'get', 'delete', 'list', 'clear'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument for the operation.',
                            'set/append: "key: value" — exactly one key per call, colon-separated.',
                            'get/delete: key name only.',
                            'list/clear: leave empty.',
                            'Example for set: "current_task: analyzing logs"',
                            'Example for append: "notes: also check error rate"',
                        ]),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return 'Workspace error: invalid command format. '
            . "Use [workspace set]key: value[/workspace] or see instructions.";
    }

    // -------------------------------------------------------------------------
    // CommandPluginInterface — config
    // -------------------------------------------------------------------------

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Workspace Plugin',
                'description' => 'Allow persistent key-value scratchpad across sessions',
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function getDefaultConfig(): array
    {
        return ['enabled' => true];
    }

    public function testConnection(): bool
    {
        return $this->isEnabled();
    }

    // -------------------------------------------------------------------------
    // Plugin lifecycle
    // -------------------------------------------------------------------------

    public function pluginReady(AiPreset $preset): void
    {
        $scope = $this->shortcodeScopeResolver->preset($preset->getId());
        $this->placeholderService->registerDynamic(
            'workspace',
            'Current workspace — all persistent key-value entries for this preset',
            fn () => $this->workspaceService->getFormatted($preset),
            $scope
        );
    }

    // -------------------------------------------------------------------------
    // Commands
    // -------------------------------------------------------------------------

    /**
     * Default execute — alias for set, so [workspace]key: value[/workspace] also works.
     */
    public function execute(string $content, AiPreset $preset): string
    {
        return $this->set($content, $preset);
    }

    /**
     * [workspace set]key: value[/workspace]
     */
    public function set(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Workspace plugin is disabled.';
        }

        [$key, $value] = $this->parseKeyValue($content);
        if ($key === null) {
            return 'Error: Format must be "key: value".';
        }

        $this->workspaceService->set($preset, $key, $value);

        return "Workspace key [{$key}] set successfully.";
    }

    /**
     * [workspace append]key: additional text[/workspace]
     */
    public function append(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Workspace plugin is disabled.';
        }

        [$key, $value] = $this->parseKeyValue($content);
        if ($key === null) {
            return 'Error: Format must be "key: value".';
        }

        $this->workspaceService->append($preset, $key, $value);

        return "Workspace key [{$key}] updated (appended).";
    }

    /**
     * [workspace get]key[/workspace]
     */
    public function get(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Workspace plugin is disabled.';
        }

        $key   = trim($content);
        $value = $this->workspaceService->get($preset, $key);

        if ($value === null) {
            return "Workspace key [{$key}] does not exist.";
        }

        return "[{$key}]\n{$value}";
    }

    /**
     * [workspace delete]key[/workspace]
     */
    public function delete(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Workspace plugin is disabled.';
        }

        $key     = trim($content);
        $deleted = $this->workspaceService->delete($preset, $key);

        return $deleted
            ? "Workspace key [{$key}] deleted."
            : "Workspace key [{$key}] not found.";
    }

    /**
     * [workspace clear][/workspace]
     */
    public function clear(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Workspace plugin is disabled.';
        }

        $this->workspaceService->clear($preset);

        return 'Workspace cleared.';
    }

    /**
     * [workspace list][/workspace]
     */
    public function list(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Workspace plugin is disabled.';
        }

        $entries = $this->workspaceService->all($preset);

        if (empty($entries)) {
            return 'Workspace is empty.';
        }

        $keys = array_keys($entries);
        return 'Workspace keys: ' . implode(', ', $keys);
    }

    // -------------------------------------------------------------------------
    // Merge behaviour
    // -------------------------------------------------------------------------

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function getSelfClosingTags(): array
    {
        return ['clear', 'list'];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse "key: value" content into [key, value].
     * Returns [null, null] on failure.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function parseKeyValue(string $content): array
    {
        $pos = strpos($content, ':');
        if ($pos === false) {
            return [null, null];
        }

        $key   = trim(substr($content, 0, $pos));
        $value = trim(substr($content, $pos + 1));

        if ($key === '') {
            return [null, null];
        }

        return [$key, $value];
    }

}

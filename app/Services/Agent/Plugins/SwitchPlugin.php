<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\PresetPluginDataServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * SwitchPlugin — conditional prompt switching.
 *
 * Allows the agent to swap named text switches into a placeholder
 * inside its own prompt without switching the entire preset prompt.
 *
 * Switches are stored in preset_plugin_data (plugin_code = 'switch').
 * The agent sees only switch codes — content is opaque unless
 * the `allow_inspect` config option is enabled.
 *
 * Basic commands:
 *   [switch]code[/switch]                  — activate a switch
 *   [switch list][/switch]                 — list available codes
 *   [switch current][/switch]              — show active code
 *
 * Optional (require allow_inspect = true):
 *   [switch get]code[/switch]              — read switch content
 *
 * Optional (require allow_write = true):
 *   [switch write]code | content[/switch]  — create or overwrite a switch
 *   [switch remove]code[/switch]           — delete a switch
 */
class SwitchPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    /** Key used to persist the active switch code in preset config. */
    private const ACTIVE_KEY = 'active_switch_code';

    /** plugin_code value in preset_plugin_data. */
    public const PLUGIN_CODE = 'switch';

    public function __construct(
        protected PresetPluginDataServiceInterface $dataService,
        protected PlaceholderServiceInterface $placeholderService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PluginMetadataServiceInterface $metadataService,
        protected LoggerInterface $logger,
    ) {
    }

    // ── Identity ──────────────────────────────────────────────────────────────

    public function getName(): string
    {
        return self::PLUGIN_CODE;
    }

    public function getDescription(array $config = []): string
    {
        return 'Conditional prompt switch. Activates named text switches inside the prompt without changing the full preset.';
    }

    public function getInstructions(array $config = []): array
    {
        $base = [
            'Activate a switch:    [switch]code[/switch]',
            'List available:      [switch list][/switch]',
            'Show active:         [switch current][/switch]',
        ];

        if ($config['allow_inspect'] ?? false) {
            $base[] = 'Read switch content:  [switch get]code[/switch]';
        }

        if ($config['allow_write'] ?? false) {
            $base[] = 'Create/overwrite:    [switch write]code | content[/switch]';
            $base[] = 'Delete a switch:      [switch remove]code[/switch]';
        }

        return $base;
    }

    public function getToolSchema(array $config = []): array
    {
        $methods = ['execute', 'list', 'current'];

        if ($config['allow_inspect'] ?? false) {
            $methods[] = 'get';
        }
        if ($config['allow_write'] ?? false) {
            $methods[] = 'write';
            $methods[] = 'remove';
        }

        return [
            'name'        => 'switch',
            'description' => 'Switch the active prompt switch. '
                . 'Switches change which text is injected into the system message via placeholder. '
                . 'You see switch codes only; content is defined by the user. '
                . 'Activation takes effect from the next cycle.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method'  => [
                        'type' => 'string',
                        'enum' => $methods,
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => 'execute/get/remove: switch code. write: "code | content". list/current: leave empty.',
                    ],
                ],
                'required' => ['method'],
            ],
        ];
    }

    // ── Config ────────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'     => 'checkbox',
                'label'    => 'Enable Switch Plugin',
                'required' => false,
            ],
            'allow_inspect' => [
                'type'        => 'checkbox',
                'label'       => 'Allow switch inspection',
                'description' => 'Let the agent read switch contents via [switch get]',
                'value'       => false,
                'required'    => false,
            ],
            'allow_write' => [
                'type'        => 'checkbox',
                'label'       => 'Allow switch editing',
                'description' => 'Let the agent create or delete switches via [switch write] / [switch remove]',
                'value'       => false,
                'required'    => false,
            ],
            'switches' => [
                'type'        => 'plugin_data_list',
                'label'       => 'Prompt Switches',
                'description' => 'Named text switches the agent can activate. The agent sees codes only.',
                'key_label'   => 'Code',
                'value_label' => 'Content',
                'value_type'  => 'textarea',
                'ordered'     => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'        => false,
            'allow_inspect'  => false,
            'allow_write'    => false,
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    // ── Placeholder registration ──────────────────────────────────────────────

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());

        // [[active_switch]] — injects the content of the currently active switch
        $this->placeholderService->registerDynamic(
            'active_switch',
            'Content of the currently active prompt switch',
            function () use ($context) {
                $code = $this->activeCode($context);
                if (!$code) {
                    return '';
                }
                $entry = $this->dataService->find($context->preset, self::PLUGIN_CODE, $code);
                return $entry?->value ?? '';
            },
            $scope
        );

        // [[active_switch_code]] — just the code, useful for debugging in prompt
        $this->placeholderService->registerDynamic(
            'active_switch_code',
            'Code of the currently active prompt switch',
            fn () => $this->activeCode($context) ?? 'none',
            $scope
        );

        // [[available_switches]] — comma-separated list of available switch codes
        $this->placeholderService->registerDynamic(
            'available_switches',
            'Comma-separated list of available switch codes for this preset',
            function () use ($context) {
                $codes = array_keys($this->dataService->map($context->preset, self::PLUGIN_CODE));
                return empty($codes) ? 'none' : implode(', ', $codes);
            },
            $scope
        );
    }

    // ── Commands ──────────────────────────────────────────────────────────────

    /**
     * [switch]code[/switch] — activate a switch by code.
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Switch plugin is disabled.';
        }

        $code = trim($content);
        if (empty($code)) {
            return 'Error: Switch code required. Use [switch]code[/switch].';
        }

        // Verify the switch exists
        $entry = $this->dataService->find($context->preset, self::PLUGIN_CODE, $code);
        if (!$entry) {
            $available = $this->availableCodes($context);
            $hint = $available ? ' Available: ' . implode(', ', $available) : ' No switches defined yet.';
            return "Error: Switch '{$code}' not found.{$hint}";
        }

        $previous = $this->activeCode($context);
        $this->setActiveCode($context, $code);

        $this->logger->info('SwitchPlugin: switch activated', [
            'preset_id' => $context->preset->id,
            'from'      => $previous,
            'to'        => $code,
        ]);

        return "Switch '{$code}' activated. Takes effect from the next cycle.";
    }

    /**
     * [switch list][/switch] — list available switch codes.
     */
    public function list(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Switch plugin is disabled.';
        }

        $codes = $this->availableCodes($context);
        if (empty($codes)) {
            return 'No switches defined for this preset.';
        }

        $active = $this->activeCode($context);
        $lines  = ['Available switches:'];
        foreach ($codes as $code) {
            $marker  = $code === $active ? ' ← active' : '';
            $lines[] = "  • {$code}{$marker}";
        }

        return implode("\n", $lines);
    }

    /**
     * [switch current][/switch] — show the active switch code.
     */
    public function current(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Switch plugin is disabled.';
        }

        $code = $this->activeCode($context);
        return $code ? "Active switch: '{$code}'" : 'No switch is currently active.';
    }

    /**
     * [switch get]code[/switch] — read switch content (requires allow_inspect).
     */
    public function get(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Switch plugin is disabled.';
        }
        if (!$context->get('allow_inspect', false)) {
            return 'Error: Switch inspection is disabled for this preset.';
        }

        $code  = trim($content);
        $entry = $this->dataService->find($context->preset, self::PLUGIN_CODE, $code);

        if (!$entry) {
            return "Error: Switch '{$code}' not found.";
        }

        return "Switch '{$code}':\n" . $entry->value;
    }

    /**
     * [switch write]code | content[/switch] — create or overwrite (requires allow_write).
     */
    public function write(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Switch plugin is disabled.';
        }
        if (!$context->get('allow_write', false)) {
            return 'Error: Switch editing is disabled for this preset.';
        }

        $parts = explode('|', $content, 2);
        if (count($parts) < 2) {
            return 'Error: Format is [switch write]code | content[/switch].';
        }

        [$code, $switchContent] = [trim($parts[0]), trim($parts[1])];

        if (empty($code)) {
            return 'Error: Switch code cannot be empty.';
        }

        $existing = $this->dataService->find($context->preset, self::PLUGIN_CODE, $code);

        if ($existing) {
            $this->dataService->update($existing->id, ['value' => $switchContent]);
            return "Switch '{$code}' updated.";
        }

        $this->dataService->create($context->preset, self::PLUGIN_CODE, $code, $switchContent);
        return "Switch '{$code}' created.";
    }

    /**
     * [switch remove]code[/switch] — delete a switch (requires allow_write).
     */
    public function remove(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Switch plugin is disabled.';
        }
        if (!$context->get('allow_write', false)) {
            return 'Error: Switch editing is disabled for this preset.';
        }

        $code  = trim($content);
        $entry = $this->dataService->find($context->preset, self::PLUGIN_CODE, $code);

        if (!$entry) {
            return "Error: Switch '{$code}' not found.";
        }

        // Deactivate if it was active
        if ($this->activeCode($context) === $code) {
            $this->setActiveCode($context, null);
        }

        $this->dataService->delete($entry->id);
        return "Switch '{$code}' deleted.";
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Get the currently active switch code from preset config.
     * Stored as a regular config key so it survives context rebuilds.
     */
    private function activeCode(PluginExecutionContext $context): ?string
    {
        return $this->metadataService->get(
            $context->preset,
            self::PLUGIN_CODE,
            self::ACTIVE_KEY
        ) ?: null;
    }

    /**
     * Persist the active switch code into the preset plugin config.
     */
    private function setActiveCode(PluginExecutionContext $context, ?string $code): void
    {
        $this->metadataService->set(
            $context->preset,
            self::PLUGIN_CODE,
            self::ACTIVE_KEY,
            $code
        );
    }

    /**
     * Return sorted list of all defined switch codes.
     */
    private function availableCodes(PluginExecutionContext $context): array
    {
        return array_keys($this->dataService->map($context->preset, self::PLUGIN_CODE));
    }

    // ── Interface boilerplate ─────────────────────────────────────────────────

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
        return ['list', 'current'];
    }
}

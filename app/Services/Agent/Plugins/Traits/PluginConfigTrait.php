<?php

namespace App\Services\Agent\Plugins\Traits;

/**
 * Trait for plugin configuration boilerplate.
 *
 * What the trait still provides:
 *   - Default getConfigFields() with just the 'enabled' checkbox
 *   - Default validateConfig() returning no errors
 *
 * Plugins typically override getConfigFields() and getDefaultConfig() with
 * their own definitions, so the defaults here are mostly fallback safety.
 */
trait PluginConfigTrait
{
    /**
     * Default tool schema — empty. Plugins override if they need
     * a custom OpenAI-compatible function schema. An empty return
     * signals "use the auto-generated schema from description and
     * inferred methods".
     */
    public function getToolSchema(array $config = []): array
    {
        return [];
    }

    /**
     * Default config fields. Override in concrete plugins.
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Plugin',
                'description' => 'Enable or disable this plugin',
                'required' => false,
            ],
        ];
    }

    /**
     * Default validator: accepts any input. Override for real validation.
     */
    public function validateConfig(array $config): array
    {
        return [];
    }

    /**
     * Default config values. Override in concrete plugins to set sensible defaults.
     */
    public function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
        ];
    }
}

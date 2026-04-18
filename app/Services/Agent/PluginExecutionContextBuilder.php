<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PluginExecutionContextBuilderInterface;
use App\Models\AiPreset;
use App\Models\PresetPluginConfig;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;

/**
 * Default builder. Resolves plugin config from PresetPluginConfig and applies
 * the preset's plugins_disabled blacklist to determine the enabled flag.
 */
class PluginExecutionContextBuilder implements PluginExecutionContextBuilderInterface
{
    public function __construct(
        protected PresetPluginConfig $presetPluginConfigModel
    ) {
    }

    public function build(CommandPluginInterface $plugin, AiPreset $preset): PluginExecutionContext
    {
        $pluginName = $plugin->getName();

        $record = $this->presetPluginConfigModel::findOrCreateForPreset(
            $preset->getId(),
            $pluginName,
            $plugin->getDefaultConfig()
        );

        // blacklist check. plugins_disabled is a comma-separated
        // string at the preset level — a kill switch that overrides the
        // per-plugin is_enabled flag. If the plugin is on the list, the
        // context is built with enabled=false even if its own row says true.
        $disabled = $this->parseDisabledList($preset->getPluginsDisabled());
        $blacklisted = in_array($pluginName, $disabled, true);

        $enabled = $record->is_enabled && !$blacklisted;

        // Effective config: prefer the stored config_data, fall back to
        // plugin defaults. config_data may be null on a freshly seeded
        // record, but findOrCreateForPreset already populates it on insert,
        // so this is also a safety net rather than a hot path.
        $config = $record->config_data ?? $plugin->getDefaultConfig();

        return new PluginExecutionContext(
            preset:  $preset,
            config:  $config,
            enabled: $enabled,
        );
    }

    /**
     * Parse "memory, journal,  workspace " → ["memory", "journal", "workspace"].
     * Tolerates whitespace and empty entries.
     */
    private function parseDisabledList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}

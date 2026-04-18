<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;

/**
 * Builds a PluginExecutionContext for a given (plugin, preset) pair.
 *
 * Encapsulates the rules for how a plugin's effective configuration is
 * resolved for a specific preset:
 *
 *   1. Look up PresetPluginConfig record for (preset_id, plugin_name).
 *   2. If missing — create one populated with $plugin->getDefaultConfig().
 *   3. Honour the preset's plugins_disabled blacklist — if the plugin name
 *      appears there, the resulting context has enabled=false regardless
 *      of the per-preset is_enabled flag.
 *   4. Build and return an immutable PluginExecutionContext.
 *
 * Extracted as a separate service from PluginManager so the manager itself
 * stays focused on lifecycle/orchestration. Builder has a single concrete
 * responsibility — context resolution — and can be unit-tested in isolation.
 */
interface PluginExecutionContextBuilderInterface
{
    /**
     * Build the execution context for a plugin in the scope of a preset.
     *
     * @param  CommandPluginInterface $plugin  Already-instantiated plugin (for defaults)
     * @param  AiPreset               $preset  Preset whose config to apply
     * @return PluginExecutionContext
     */
    public function build(CommandPluginInterface $plugin, AiPreset $preset): PluginExecutionContext;
}

<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;

/**
 * Plugin manager interface — stateless, per-preset API.
 *
 * Every method that operates on configuration takes the target preset
 * explicitly. There is no "current preset" concept; plugins are
 * singletons, configuration lives in PresetPluginConfig rows, and
 * execution-time state lives in PluginExecutionContext DTOs that are
 * built on demand.
 *
 * The legacy stateful API (setCurrentPreset, getAllPluginsInfo, etc.)
 * was removed entirely. If you're migrating old call sites, map them
 * to the *ForPreset variants:
 *
 *   getAllPluginsInfo()         → getAllPluginsInfoForPreset($preset)
 *   getPluginInfo($plugin)      → getPluginInfoForPreset($name, $preset)
 *   updatePluginConfig(...)     → updatePluginConfigForPreset($name, $preset, $cfg)
 *   resetPluginConfig(...)      → resetPluginConfigForPreset($name, $preset)
 *   setPluginEnabled(...)       → setPluginEnabledForPreset($name, $preset, $bool)
 *   getPluginStatistics()       → getPluginStatisticsForPreset($preset)
 *   getConfiguredPlugin($name)  → configurePluginFor($name, $preset)
 */
interface PluginManagerInterface
{
    /**
     * Materialize PresetPluginConfig records for the given preset.
     *
     * Iterates over all registered plugins and ensures a per-preset config
     * record exists for each one, populated with defaults from
     * $plugin->getDefaultConfig(). Idempotent — existing records are not
     * overwritten.
     *
     * Called immediately after a preset is created or duplicated so the
     * preset has a complete, queryable plugin-configuration set from the
     * moment of creation.
     *
     * @param AiPreset $preset
     * @return void
     */
    public function initializeConfigsForPreset(AiPreset $preset): void;

    /**
     * Build a PluginExecutionContext for a (plugin, preset) pair.
     *
     * Convenience entry point — internally delegates to
     * PluginExecutionContextBuilderInterface. Useful when callers have
     * a plugin name + preset and just want the resolved context.
     *
     * @return PluginExecutionContext|null null if the plugin isn't registered
     */
    public function buildContextFor(string $pluginName, AiPreset $preset): ?PluginExecutionContext;

    /**
     * @inheritDoc
     *
     * Returns enabled plugins together with their already-built contexts, so
     * downstream consumers (instruction builder, tool-schema builder) can
     * read plugin config without rebuilding it. Used for dynamic
     * getDescription/getInstructions/getToolSchema that vary per preset.
     *
     * @param AiPreset $preset
     * @return array
     */
    public function getEnabledPluginsWithContextsForPreset(AiPreset $preset): array;

    /**
     * Return the plugin instance (singleton) for a given preset.
     *
     * plugins are stateless function bundles; anything
     * preset-specific comes through the PluginExecutionContext that the
     * caller passes to execute() / callMethod(). This method exists only
     * to give callers a convenient "get me the plugin for this preset"
     * entry point without poking at the registry directly.
     *
     * @return CommandPluginInterface|null null if the plugin isn't registered
     */
    public function configurePluginFor(string $pluginName, AiPreset $preset): ?CommandPluginInterface;

    /**
     * Get all enabled plugins for a preset.
     *
     * "Enabled" means the per-preset PresetPluginConfig.is_enabled is true
     * AND the plugin isn't on the preset's plugins_disabled blacklist.
     *
     * @return array<string, CommandPluginInterface> keyed by plugin name
     */
    public function getEnabledPluginsForPreset(AiPreset $preset): array;

    /**
     * Info (description, current config, enabled flag, etc.) for every
     * plugin, in the scope of the given preset.
     *
     * @return array<string, array> keyed by plugin name
     */
    public function getAllPluginsInfoForPreset(AiPreset $preset): array;

    /**
     * Info for a single plugin in the scope of a preset.
     *
     * @return array|null null if the plugin isn't registered
     */
    public function getPluginInfoForPreset(string $pluginName, AiPreset $preset): ?array;

    /**
     * Update a plugin's config for a specific preset.
     *
     * Validates via $plugin->validateConfig(), persists to PresetPluginConfig,
     * returns the standard {success, errors|config} shape used by controllers.
     *
     * @param string $pluginName
     * @param AiPreset $preset
     * @param array $config
     * @return array
     */
    public function updatePluginConfigForPreset(string $pluginName, AiPreset $preset, array $config): array;

    /**
     * Reset a plugin's config to its defaults for a specific preset.
     *
     * @param string $pluginName
     * @param AiPreset $preset
     * @return array
     */
    public function resetPluginConfigForPreset(string $pluginName, AiPreset $preset): array;

    /**
     * Enable or disable a plugin for a specific preset.
     *
     * @param string $pluginName
     * @param AiPreset $preset
     * @param boolean $enabled
     * @return array
     */
    public function setPluginEnabledForPreset(string $pluginName, AiPreset $preset, bool $enabled): array;

    /**
     * Per-preset plugin statistics (total, enabled, disabled).
     *
     * @param AiPreset $preset
     * @return array
     */
    public function getPluginStatisticsForPreset(AiPreset $preset): array;

    /**
     * Copy plugin configurations from one preset to another.
     * Already preset-aware by design (takes two preset IDs explicitly).
     *
     * @param integer $fromPresetId
     * @param integer $toPresetId
     * @return array
     */
    public function copyPluginConfigsBetweenPresets(int $fromPresetId, int $toPresetId): array;
}

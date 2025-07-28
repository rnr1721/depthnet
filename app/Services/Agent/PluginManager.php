<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginManagerInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Models\AiPreset;
use App\Models\PluginConfig;
use App\Models\PresetPluginConfig;
use Illuminate\Database\DatabaseManager;
use Illuminate\Cache\CacheManager;
use Psr\Log\LoggerInterface;

/**
 * Plugin manager responsible for managing plugin configurations,
 * testing connections, and ensuring plugins have up-to-date settings
 */
class PluginManager implements PluginManagerInterface
{
    protected ?AiPreset $currentPreset = null;

    public function __construct(
        protected PluginRegistryInterface $registry,
        protected PresetRegistryInterface $presetRegistry,
        protected PluginConfig $pluginConfigModel,
        protected PresetPluginConfig $presetPluginConfigModel,
        protected DatabaseManager $db,
        protected CacheManager $cache,
        protected LoggerInterface $logger
    ) {
        $this->initializePlugins();
    }

    /**
     * Initialize all plugins with their configurations from database
     *
     * @return void
     */
    protected function initializePlugins(): void
    {
        $preset = $this->presetRegistry->getDefaultPreset();
        $this->setCurrentPreset($preset);

        $plugins = $this->registry->allRegistered();

        foreach ($plugins as $plugin) {
            // Initialize plugin config in database if not exists
            $this->initializePluginConfig($plugin);

            // Apply current configuration from database
            $this->applyDatabaseConfigToPlugin($plugin);
        }
    }

    /**
     * Initialize plugin configuration in database
     *
     * @param CommandPluginInterface $plugin
     * @return void
     */
    protected function initializePluginConfig(CommandPluginInterface $plugin): void
    {
        $this->pluginConfigModel->findOrCreateByName(
            $plugin->getName(),
            $plugin->getDefaultConfig()
        );
    }

    /**
     * Initialize plugin configuration for specific preset
     *
     * @param CommandPluginInterface $plugin
     * @param AiPreset $preset
     * @return PresetPluginConfig
     */
    protected function initializePresetPluginConfig(CommandPluginInterface $plugin, AiPreset $preset): PresetPluginConfig
    {
        return $this->presetPluginConfigModel->findOrCreateForPreset(
            $preset->getId(),
            $plugin->getName(),
            $plugin->getDefaultConfig()
        );
    }

    /**
     * Apply database configuration to plugin instance
     * Now supports per-preset configuration
     *
     * @param CommandPluginInterface $plugin
     * @return void
     */
    protected function applyDatabaseConfigToPlugin(CommandPluginInterface $plugin): void
    {
        if (!$this->currentPreset) {
            $this->currentPreset = $this->presetRegistry->getDefaultPreset();
        }

        // Try to get preset-specific configuration first
        $presetConfig = $this->getPresetPluginConfig($plugin->getName(), $this->currentPreset);

        if ($presetConfig) {
            // Use preset-specific configuration
            if ($presetConfig->config_data) {
                $plugin->updateConfig($presetConfig->config_data);
            }
            $plugin->setEnabled($presetConfig->is_enabled);
        } else {
            // Fallback to global configuration
            $globalConfig = $this->pluginConfigModel->findOrCreateByName(
                $plugin->getName(),
                $plugin->getDefaultConfig()
            );

            if ($globalConfig->config_data) {
                $plugin->updateConfig($globalConfig->config_data);
            }
            $plugin->setEnabled($globalConfig->is_enabled);
        }
    }

    /**
     * Get plugin configuration for specific preset
     *
     * @param string $pluginName
     * @param AiPreset $preset
     * @return PresetPluginConfig|null
     */
    protected function getPresetPluginConfig(string $pluginName, AiPreset $preset): ?PresetPluginConfig
    {
        return $this->presetPluginConfigModel
            ->where('preset_id', $preset->getId())
            ->where('plugin_name', $pluginName)
            ->first();
    }

    /**
     * Ensure plugin has latest configuration from database
     *
     * @param CommandPluginInterface $plugin
     * @return void
     */
    protected function ensurePluginConfigured(CommandPluginInterface $plugin): void
    {
        if (!$this->currentPreset) {
            return;
        }

        $cacheKey = "plugin_config_applied_{$plugin->getName()}_{$this->currentPreset->getId()}";

        // Check if we recently applied config (cache for 30 seconds)
        if (!$this->cache->has($cacheKey)) {
            $this->applyDatabaseConfigToPlugin($plugin);
            $this->cache->put($cacheKey, true, 30);
        }
    }

    /**
     * @inheritDoc
     */
    public function getAllPluginsInfo(): array
    {
        if (!$this->currentPreset) {
            $this->currentPreset = $this->presetRegistry->getDefaultPreset();
        }

        $plugins = $this->registry->allRegistered();
        $info = [];

        foreach ($plugins as $plugin) {
            $info[$plugin->getName()] = $this->getPluginInfo($plugin);
        }

        return $info;
    }

    /**
     * @inheritDoc
     */
    public function getPluginInfo(CommandPluginInterface $plugin): array
    {
        if (!$this->currentPreset) {
            $this->currentPreset = $this->presetRegistry->getDefaultPreset();
        }

        // Try preset-specific config first
        $presetConfig = $this->getPresetPluginConfig($plugin->getName(), $this->currentPreset);

        if ($presetConfig) {
            return [
                'name' => $plugin->getName(),
                'description' => $plugin->getDescription(),
                'enabled' => $presetConfig->is_enabled,
                'config_fields' => $plugin->getConfigFields(),
                'current_config' => $presetConfig->config_data ?? $plugin->getDefaultConfig(),
                'default_config' => $plugin->getDefaultConfig(),
                'instructions' => $plugin->getInstructions(),
                'available_methods' => $plugin->getAvailableMethods(),
                'health_status' => 'unknown', // Per-preset health status would need additional implementation
                'last_tested_at' => null,
                'last_test_error' => null,
                'configuration_source' => 'preset', // Indicates this is preset-specific
                'preset_id' => $this->currentPreset->getId(),
                'preset_name' => $this->currentPreset->getName(),
            ];
        }

        // Fallback to global config
        $globalConfig = $this->pluginConfigModel->where('plugin_name', $plugin->getName())->first();

        if (!$globalConfig) {
            $globalConfig = $this->pluginConfigModel->findOrCreateByName(
                $plugin->getName(),
                $plugin->getDefaultConfig()
            );
        }

        return [
            'name' => $plugin->getName(),
            'description' => $plugin->getDescription(),
            'enabled' => $globalConfig->is_enabled,
            'config_fields' => $plugin->getConfigFields(),
            'current_config' => $globalConfig->config_data ?? $plugin->getDefaultConfig(),
            'default_config' => $plugin->getDefaultConfig(),
            'instructions' => $plugin->getInstructions(),
            'available_methods' => $plugin->getAvailableMethods(),
            'health_status' => $globalConfig->health_status,
            'last_tested_at' => $globalConfig->last_test_at?->toISOString(),
            'last_test_error' => $globalConfig->last_test_error,
            'configuration_source' => 'global', // Indicates this is global config
            'preset_id' => $this->currentPreset->getId(),
            'preset_name' => $this->currentPreset->getName(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function updatePluginConfig(string $pluginName, array $config): array
    {
        $plugin = $this->registry->get($pluginName);

        if (!$plugin) {
            return [
                'success' => false,
                'errors' => ['plugin' => "Plugin '{$pluginName}' not found"]
            ];
        }

        if (!$this->currentPreset) {
            return [
                'success' => false,
                'errors' => ['preset' => 'No current preset set']
            ];
        }

        // Validate configuration
        $errors = $plugin->validateConfig($config);

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors' => $errors
            ];
        }

        try {
            return $this->db->transaction(function () use ($plugin, $pluginName, $config) {
                // Get or create preset-specific plugin config
                $presetPluginConfig = $this->initializePresetPluginConfig($plugin, $this->currentPreset);

                // Update plugin configuration in database
                $presetPluginConfig->updateConfig($config);

                // Apply configuration to ALL plugin instances
                $this->applyConfigToAllPluginInstances($pluginName, $config, $presetPluginConfig->is_enabled);

                // Clear any cached plugin data
                $this->clearPluginCache($pluginName);

                // Test connection if plugin is enabled
                $connectionStatus = $presetPluginConfig->is_enabled ? $this->testPluginConnection($plugin) : true;

                return [
                    'success' => true,
                    'config' => $presetPluginConfig->config_data,
                    'connection_status' => $connectionStatus,
                    'preset_id' => $this->currentPreset->getId(),
                ];
            });

        } catch (\Exception $e) {
            $this->logger->error("Failed to update plugin config for preset", [
                'plugin' => $pluginName,
                'preset_id' => $this->currentPreset->getId(),
                'error' => $e->getMessage(),
                'config' => $config
            ]);

            return [
                'success' => false,
                'errors' => ['general' => 'Failed to update configuration: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Apply configuration to all instances of a plugin
     *
     * @param string $pluginName
     * @param array $config
     * @param boolean $enabled
     * @return void
     */
    protected function applyConfigToAllPluginInstances(string $pluginName, array $config, bool $enabled): void
    {
        // Apply to registry instance
        $plugin = $this->registry->get($pluginName);
        if ($plugin) {
            $plugin->updateConfig($config);
            $plugin->setEnabled($enabled);
        }

        // Apply to all registered instances (including the one above)
        foreach ($this->registry->allRegistered() as $registeredPlugin) {
            if ($registeredPlugin->getName() === $pluginName) {
                $registeredPlugin->updateConfig($config);
                $registeredPlugin->setEnabled($enabled);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function testPluginConnection(CommandPluginInterface $plugin): bool
    {
        // Ensure plugin has latest config before testing
        $this->ensurePluginConfigured($plugin);

        $pluginConfig = $this->pluginConfigModel->findOrCreateByName(
            $plugin->getName(),
            $plugin->getDefaultConfig()
        );

        // Skip testing if recently tested (cache for 1 minute)
        $cacheKey = "plugin_test_{$plugin->getName()}";
        if ($this->cache->has($cacheKey) && !$pluginConfig->needsTesting()) {
            return $this->cache->get($cacheKey);
        }

        try {
            $startTime = microtime(true);

            $result = $plugin->testConnection();

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            // Record test result in database
            $pluginConfig->recordTestResult($result, $responseTime);

            // Cache result
            $this->cache->put($cacheKey, $result, 60);

            return $result;

        } catch (\Exception $e) {
            $this->logger->warning("Plugin connection test failed", [
                'plugin' => $plugin->getName(),
                'error' => $e->getMessage()
            ]);

            // Record failure in database
            $pluginConfig->recordTestResult(false, null, $e->getMessage());

            // Cache failure
            $this->cache->put($cacheKey, false, 60);

            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function testAllPlugins(): array
    {
        if (!$this->currentPreset) {
            return [];
        }

        $enabledConfigs = $this->presetPluginConfigModel->getEnabledForPreset($this->currentPreset->getId());
        $results = [];

        foreach ($enabledConfigs as $config) {
            $plugin = $this->registry->get($config->plugin_name);
            if (!$plugin) {
                continue;
            }

            // Ensure plugin has latest config before testing
            $this->ensurePluginConfigured($plugin);

            $results[$plugin->getName()] = [
                'enabled' => true,
                'connection_status' => $this->testPluginConnection($plugin),
                'health_status' => 'unknown', // Would need per-preset health tracking
                'preset_id' => $this->currentPreset->getId(),
            ];
        }

        return $results;
    }

    /**
     * @inheritDoc
     */
    public function getPluginConfigSchema(string $pluginName): ?array
    {
        $plugin = $this->registry->get($pluginName);

        if (!$plugin) {
            return null;
        }

        if (!$this->currentPreset) {
            return null;
        }

        $presetPluginConfig = $this->getPresetPluginConfig($pluginName, $this->currentPreset);

        return [
            'name' => $plugin->getName(),
            'description' => $plugin->getDescription(),
            'fields' => $plugin->getConfigFields(),
            'default_values' => $plugin->getDefaultConfig(),
            'current_values' => $presetPluginConfig?->config_data ?? $plugin->getDefaultConfig(),
            'preset_id' => $this->currentPreset->getId(),
            'preset_name' => $this->currentPreset->getName(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function setPluginEnabled(string $pluginName, bool $enabled): array
    {
        $plugin = $this->registry->get($pluginName);

        if (!$plugin) {
            return [
                'success' => false,
                'errors' => ['plugin' => "Plugin '{$pluginName}' not found"]
            ];
        }

        if (!$this->currentPreset) {
            return [
                'success' => false,
                'errors' => ['preset' => 'No current preset set']
            ];
        }

        try {
            return $this->db->transaction(function () use ($plugin, $pluginName, $enabled) {
                // Get or create preset-specific plugin config
                $presetPluginConfig = $this->initializePresetPluginConfig($plugin, $this->currentPreset);

                // Update enabled state in database
                $presetPluginConfig->update(['is_enabled' => $enabled]);

                // Apply to all plugin instances
                $this->applyConfigToAllPluginInstances(
                    $pluginName,
                    $presetPluginConfig->config_data ?? [],
                    $enabled
                );

                // Clear cache
                $this->clearPluginCache($pluginName);

                // Test connection if enabling
                if ($enabled) {
                    $this->testPluginConnection($plugin);
                }

                return [
                    'success' => true,
                    'enabled' => $enabled,
                    'preset_id' => $this->currentPreset->getId(),
                ];
            });

        } catch (\Exception $e) {
            $this->logger->error("Failed to set plugin enabled state for preset", [
                'plugin' => $pluginName,
                'preset_id' => $this->currentPreset->getId(),
                'enabled' => $enabled,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'errors' => ['general' => $e->getMessage()]
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function resetPluginConfig(string $pluginName): array
    {
        $plugin = $this->registry->get($pluginName);

        if (!$plugin) {
            return [
                'success' => false,
                'errors' => ['plugin' => "Plugin '{$pluginName}' not found"]
            ];
        }

        if (!$this->currentPreset) {
            return [
                'success' => false,
                'errors' => ['preset' => 'No current preset set']
            ];
        }

        try {
            return $this->db->transaction(function () use ($plugin, $pluginName) {
                // Get or create preset-specific plugin config
                $presetPluginConfig = $this->initializePresetPluginConfig($plugin, $this->currentPreset);

                // Reset to defaults
                $presetPluginConfig->resetToDefaults();

                // Apply defaults to all plugin instances
                $this->applyConfigToAllPluginInstances(
                    $pluginName,
                    $plugin->getDefaultConfig(),
                    $presetPluginConfig->is_enabled
                );

                // Clear cache
                $this->clearPluginCache($pluginName);

                return [
                    'success' => true,
                    'config' => $presetPluginConfig->config_data,
                    'preset_id' => $this->currentPreset->getId(),
                ];
            });

        } catch (\Exception $e) {
            $this->logger->error("Failed to reset plugin config for preset", [
                'plugin' => $pluginName,
                'preset_id' => $this->currentPreset->getId(),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'errors' => ['general' => $e->getMessage()]
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function copyPluginConfigsBetweenPresets(int $fromPresetId, int $toPresetId): array
    {
        try {
            $copiedCount = $this->presetPluginConfigModel->copyBetweenPresets($fromPresetId, $toPresetId);

            $this->logger->info("Plugin configurations copied between presets", [
                'from_preset_id' => $fromPresetId,
                'to_preset_id' => $toPresetId,
                'copied_count' => $copiedCount
            ]);

            return [
                'success' => true,
                'copied_count' => $copiedCount,
                'message' => "Copied {$copiedCount} plugin configurations"
            ];

        } catch (\Exception $e) {
            $this->logger->error("Failed to copy plugin configurations between presets", [
                'from_preset_id' => $fromPresetId,
                'to_preset_id' => $toPresetId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'errors' => ['general' => $e->getMessage()]
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getConfiguredPlugin(string $pluginName): ?CommandPluginInterface
    {
        $plugin = $this->registry->get($pluginName);

        if (!$plugin) {
            return null;
        }

        // Ensure plugin has latest configuration
        $this->ensurePluginConfigured($plugin);

        return $plugin;
    }

    /**
     * @inheritDoc
     */
    public function getPluginStatistics(): array
    {
        if (!$this->currentPreset) {
            return [
                'total_plugins' => 0,
                'enabled_plugins' => 0,
                'disabled_plugins' => 0,
            ];
        }

        return $this->presetPluginConfigModel->getPresetStatistics($this->currentPreset->getId());
    }

    /**
     * @inheritDoc
     */
    public function getHealthStatus(): array
    {
        if (!$this->currentPreset) {
            return [
                'overall_status' => 'unknown',
                'plugins' => []
            ];
        }

        $enabledConfigs = $this->presetPluginConfigModel->getEnabledForPreset($this->currentPreset->getId());

        if ($enabledConfigs->isEmpty()) {
            return [
                'overall_status' => 'unknown',
                'plugins' => []
            ];
        }

        // For now, we'll use global health status but filter by enabled plugins for this preset
        $plugins = [];
        foreach ($enabledConfigs as $config) {
            $globalConfig = $this->pluginConfigModel->where('plugin_name', $config->plugin_name)->first();

            $plugins[] = [
                'name' => $config->plugin_name,
                'health_status' => $globalConfig?->health_status ?? 'unknown',
                'last_test_at' => $globalConfig?->last_test_at?->toISOString(),
            ];
        }

        // Simple overall status calculation
        $healthStatuses = collect($plugins)->pluck('health_status');
        $overallStatus = 'healthy';

        if ($healthStatuses->contains('error')) {
            $overallStatus = 'error';
        } elseif ($healthStatuses->contains('warning')) {
            $overallStatus = 'warning';
        } elseif ($healthStatuses->contains('unknown')) {
            $overallStatus = 'unknown';
        }

        return [
            'overall_status' => $overallStatus,
            'plugins' => $plugins,
            'preset_id' => $this->currentPreset->getId(),
            'preset_name' => $this->currentPreset->getName(),
        ];
    }

    /**
     * Clear plugin cache
     *
     * @param string $pluginName
     * @return void
     */
    protected function clearPluginCache(string $pluginName): void
    {
        $presetId = $this->currentPreset?->getId() ?? 'global';

        $keys = [
            "plugin_test_{$pluginName}",
            "plugin_config_{$pluginName}_{$presetId}",
            "plugin_info_{$pluginName}_{$presetId}",
            "plugin_config_applied_{$pluginName}_{$presetId}",
        ];

        foreach ($keys as $key) {
            $this->cache->forget($key);
        }
    }

    /**
     * @inheritDoc
     */
    public function setCurrentPreset(AiPreset $preset): void
    {
        $this->currentPreset = $preset;
        $this->registry->setCurrentPreset($preset);

        // Re-apply configurations for the new preset
        $plugins = $this->registry->allRegistered();
        foreach ($plugins as $plugin) {
            $this->applyDatabaseConfigToPlugin($plugin);
        }
    }

    /**
     * @inheritDoc
     */
    public function getCurrentPreset(): ?AiPreset
    {
        return $this->currentPreset;
    }

}

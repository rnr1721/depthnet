<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginManagerInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Models\PluginConfig;
use Illuminate\Database\DatabaseManager;
use Illuminate\Cache\CacheManager;
use Psr\Log\LoggerInterface;

/**
 * Plugin manager responsible for managing plugin configurations,
 * testing connections, and ensuring plugins have up-to-date settings
 */
class PluginManager implements PluginManagerInterface
{
    public function __construct(
        protected PluginRegistryInterface $registry,
        protected PresetRegistryInterface $presetRegistry,
        protected PluginConfig $pluginConfigModel,
        protected DatabaseManager $db,
        protected CacheManager $cache,
        protected LoggerInterface $logger
    ) {
        $this->initializePlugins();
    }

    /**
     * Initialize all plugins with their configurations from database
     */
    protected function initializePlugins(): void
    {
        $currentPreset = $this->presetRegistry->getDefaultPreset();
        $plugins = $this->registry->allRegistered();

        foreach ($plugins as $plugin) {
            $this->registry->setCurrentPreset($currentPreset);

            // Initialize plugin config in database if not exists
            $this->initializePluginConfig($plugin);

            // Apply current configuration from database
            $this->applyDatabaseConfigToPlugin($plugin);
        }
    }

    /**
     * Initialize plugin configuration in database
     */
    protected function initializePluginConfig(CommandPluginInterface $plugin): void
    {
        $this->pluginConfigModel->findOrCreateByName(
            $plugin->getName(),
            $plugin->getDefaultConfig()
        );
    }

    /**
     * Apply database configuration to plugin instance
     */
    protected function applyDatabaseConfigToPlugin(CommandPluginInterface $plugin): void
    {
        $config = $this->pluginConfigModel->findOrCreateByName(
            $plugin->getName(),
            $plugin->getDefaultConfig()
        );

        // Apply stored configuration to plugin
        if ($config->config_data) {
            $plugin->updateConfig($config->config_data);
        }
        $plugin->setEnabled($config->is_enabled);
    }

    /**
     * Ensure plugin has latest configuration from database
     */
    protected function ensurePluginConfigured(CommandPluginInterface $plugin): void
    {
        $cacheKey = "plugin_config_applied_{$plugin->getName()}";

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
        $config = $this->pluginConfigModel->where('plugin_name', $plugin->getName())->first();

        if (!$config) {
            $config = $this->pluginConfigModel->findOrCreateByName(
                $plugin->getName(),
                $plugin->getDefaultConfig()
            );
        }

        return [
            'name' => $plugin->getName(),
            'description' => $plugin->getDescription(),
            'enabled' => $config->is_enabled,
            'config_fields' => $plugin->getConfigFields(),
            'current_config' => $config->config_data ?? $plugin->getDefaultConfig(),
            'default_config' => $plugin->getDefaultConfig(),
            'instructions' => $plugin->getInstructions(),
            'available_methods' => $plugin->getAvailableMethods(),
            'health_status' => $config->health_status,
            'last_tested_at' => $config->last_test_at?->toISOString(),
            'last_test_error' => $config->last_test_error,
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
                // Get or create plugin config
                $pluginConfig = $this->pluginConfigModel->findOrCreateByName(
                    $pluginName,
                    $plugin->getDefaultConfig()
                );

                // Update plugin configuration in database
                $pluginConfig->updateConfig($config);

                // Apply configuration to ALL plugin instances
                $this->applyConfigToAllPluginInstances($pluginName, $config, $pluginConfig->is_enabled);

                // Clear any cached plugin data
                $this->clearPluginCache($pluginName);

                // Test connection if plugin is enabled
                $connectionStatus = $pluginConfig->is_enabled ? $this->testPluginConnection($plugin) : true;

                return [
                    'success' => true,
                    'config' => $pluginConfig->config_data,
                    'connection_status' => $connectionStatus
                ];
            });

        } catch (\Exception $e) {
            $this->logger->error("Failed to update plugin config", [
                'plugin' => $pluginName,
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
        $plugins = $this->registry->allRegistered();
        $results = [];

        foreach ($plugins as $plugin) {
            // Ensure plugin has latest config before testing
            $this->ensurePluginConfigured($plugin);

            $pluginConfig = $this->pluginConfigModel->findOrCreateByName(
                $plugin->getName(),
                $plugin->getDefaultConfig()
            );

            $results[$plugin->getName()] = [
                'enabled' => $pluginConfig->is_enabled,
                'connection_status' => $pluginConfig->is_enabled ? $this->testPluginConnection($plugin) : null,
                'health_status' => $pluginConfig->health_status,
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

        $pluginConfig = $this->pluginConfigModel->findOrCreateByName(
            $pluginName,
            $plugin->getDefaultConfig()
        );

        return [
            'name' => $plugin->getName(),
            'description' => $plugin->getDescription(),
            'fields' => $plugin->getConfigFields(),
            'default_values' => $plugin->getDefaultConfig(),
            'current_values' => $pluginConfig->config_data ?? $plugin->getDefaultConfig(),
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

        try {
            return $this->db->transaction(function () use ($plugin, $pluginName, $enabled) {
                // Get or create plugin config
                $pluginConfig = $this->pluginConfigModel->findOrCreateByName(
                    $pluginName,
                    $plugin->getDefaultConfig()
                );

                // Update enabled state in database
                $pluginConfig->update(['is_enabled' => $enabled]);

                // Apply to all plugin instances
                $this->applyConfigToAllPluginInstances(
                    $pluginName,
                    $pluginConfig->config_data ?? [],
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
                    'enabled' => $enabled
                ];
            });

        } catch (\Exception $e) {
            $this->logger->error("Failed to set plugin enabled state", [
                'plugin' => $pluginName,
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

        try {
            return $this->db->transaction(function () use ($plugin, $pluginName) {
                // Get or create plugin config
                $pluginConfig = $this->pluginConfigModel->findOrCreateByName(
                    $pluginName,
                    $plugin->getDefaultConfig()
                );

                // Reset to defaults
                $pluginConfig->resetToDefaults();

                // Apply defaults to all plugin instances
                $this->applyConfigToAllPluginInstances(
                    $pluginName,
                    $plugin->getDefaultConfig(),
                    $pluginConfig->is_enabled
                );

                // Clear cache
                $this->clearPluginCache($pluginName);

                return [
                    'success' => true,
                    'config' => $pluginConfig->config_data
                ];
            });

        } catch (\Exception $e) {
            $this->logger->error("Failed to reset plugin config", [
                'plugin' => $pluginName,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'errors' => ['general' => $e->getMessage()]
            ];
        }
    }

    /**
     * Get plugin instance with ensured configuration
     * This method should be used by command executors
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
        return $this->pluginConfigModel->getStatistics();
    }

    /**
     * @inheritDoc
     */
    public function getHealthStatus(): array
    {
        return $this->pluginConfigModel->getOverallHealth();
    }

    /**
     * Clear plugin cache
     */
    protected function clearPluginCache(string $pluginName): void
    {
        $keys = [
            "plugin_test_{$pluginName}",
            "plugin_config_{$pluginName}",
            "plugin_info_{$pluginName}",
            "plugin_config_applied_{$pluginName}",
        ];

        foreach ($keys as $key) {
            $this->cache->forget($key);
        }
    }
}

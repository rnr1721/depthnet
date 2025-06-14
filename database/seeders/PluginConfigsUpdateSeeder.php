<?php

namespace Database\Seeders;

use App\Models\PluginConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Updates existing plugin configurations with latest defaults from config/ai.php
 *
 * This seeder is useful when you want to update existing plugin configurations
 * with new default values or add new plugins without affecting existing custom settings.
 */
class PluginConfigUpdateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Updating plugin configurations...');

        try {
            $availablePlugins = Config::get('ai.plugins.available', []);
            $defaultConfigs = Config::get('ai.plugins.defaults', []);

            if (empty($availablePlugins)) {
                $this->command->warn('No plugins found in config/ai.php');
                return;
            }

            $createdCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;

            foreach ($availablePlugins as $pluginName) {
                try {
                    // Get default config for this plugin
                    $defaultConfig = $defaultConfigs[$pluginName] ?? [];
                    $isEnabled = $defaultConfig['enabled'] ?? false;

                    $pluginConfig = PluginConfig::where('plugin_name', $pluginName)->first();

                    if ($pluginConfig) {
                        // Update only the default_config field and version if needed
                        $updated = false;

                        // Update default_config if different
                        if ($pluginConfig->default_config !== $defaultConfig) {
                            $pluginConfig->default_config = $defaultConfig;
                            $updated = true;
                        }

                        // Merge new default settings into existing config_data
                        // This preserves user customizations while adding new default keys
                        $currentConfig = $pluginConfig->config_data ?? [];
                        $mergedConfig = $this->mergeConfigurations($currentConfig, $defaultConfig);

                        if ($pluginConfig->config_data !== $mergedConfig) {
                            $pluginConfig->config_data = $mergedConfig;
                            $updated = true;
                        }

                        if ($updated) {
                            $pluginConfig->save();
                            $this->command->info("✓ Updated plugin: {$pluginName}");
                            $updatedCount++;
                        } else {
                            $this->command->line("Plugin '{$pluginName}' is up to date");
                            $skippedCount++;
                        }

                    } else {
                        // Create new plugin config
                        PluginConfig::create([
                            'plugin_name' => $pluginName,
                            'is_enabled' => $isEnabled,
                            'config_data' => $defaultConfig,
                            'default_config' => $defaultConfig,
                            'health_status' => 'unknown',
                            'version' => null,
                            'last_test_at' => null,
                            'last_test_result' => false,
                            'last_test_error' => null,
                            'test_history' => null,
                        ]);

                        $this->command->info("✓ Created plugin: {$pluginName}" . ($isEnabled ? ' (enabled)' : ' (disabled)'));
                        $createdCount++;
                    }

                } catch (\Exception $e) {
                    $this->command->error("Failed to process plugin '{$pluginName}': " . $e->getMessage());
                    Log::error("Failed to update plugin config", [
                        'plugin' => $pluginName,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            // Check for orphaned plugins (plugins in DB but not in config)
            $this->cleanupOrphanedPlugins($availablePlugins);

            $this->command->info("\n" . str_repeat('=', 50));
            $this->command->info("Plugin configuration update completed!");
            if ($createdCount > 0) {
                $this->command->info("Created: {$createdCount} new plugins");
            }
            if ($updatedCount > 0) {
                $this->command->info("Updated: {$updatedCount} existing plugins");
            }
            if ($skippedCount > 0) {
                $this->command->info("Unchanged: {$skippedCount} plugins");
            }
            $this->command->info(str_repeat('=', 50));

        } catch (\Exception $e) {
            $this->command->error('Failed to update plugin configurations: ' . $e->getMessage());
            Log::error('Plugin update seeding failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Merge configurations preserving user customizations
     *
     * @param array $currentConfig User's current configuration
     * @param array $defaultConfig New default configuration
     * @return array Merged configuration
     */
    protected function mergeConfigurations(array $currentConfig, array $defaultConfig): array
    {
        $merged = $defaultConfig;

        // Preserve user customizations for existing keys
        foreach ($currentConfig as $key => $value) {
            if (array_key_exists($key, $defaultConfig)) {
                // Both arrays - merge recursively
                if (is_array($value) && is_array($defaultConfig[$key])) {
                    $merged[$key] = $this->mergeConfigurations($value, $defaultConfig[$key]);
                } else {
                    // Keep user value
                    $merged[$key] = $value;
                }
            } else {
                // Keep users custom key thats not in defaults
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Clean up plugins that are no longer in the configuration
     *
     * @param array $availablePlugins
     */
    protected function cleanupOrphanedPlugins(array $availablePlugins): void
    {
        $orphanedPlugins = PluginConfig::whereNotIn('plugin_name', $availablePlugins)->get();

        if ($orphanedPlugins->isNotEmpty()) {
            $this->command->info("\nFound orphaned plugins (no longer in config):");

            foreach ($orphanedPlugins as $plugin) {
                $this->command->warn("- {$plugin->plugin_name}");
            }

            if ($this->command->confirm('Do you want to disable orphaned plugins?', true)) {
                $orphanedPlugins->each(function (PluginConfig $plugin) {
                    $plugin->update(['is_enabled' => false]);
                    $this->command->info("✓ Disabled orphaned plugin: {$plugin->plugin_name}");
                });
            }
        }
    }
}

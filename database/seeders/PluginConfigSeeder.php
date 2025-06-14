<?php

namespace Database\Seeders;

use App\Models\PluginConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Seeds plugin configurations from config/ai.php
 *
 * This seeder reads the default plugin configurations from the config file
 * and creates PluginConfig records for each available plugin with their
 * default settings.
 */
class PluginConfigSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding plugin configurations...');

        try {
            $availablePlugins = Config::get('ai.plugins.available', []);
            $defaultConfigs = Config::get('ai.plugins.defaults', []);

            if (empty($availablePlugins)) {
                $this->command->warn('No plugins found in config/ai.php');
                return;
            }

            $seededCount = 0;
            $skippedCount = 0;

            foreach ($availablePlugins as $pluginName) {
                try {
                    // Plugin exists?
                    $existingConfig = PluginConfig::where('plugin_name', $pluginName)->first();

                    if ($existingConfig) {
                        $this->command->line("Plugin '{$pluginName}' already exists, skipping...");
                        $skippedCount++;
                        continue;
                    }

                    // Default config for the plugin
                    $defaultConfig = $defaultConfigs[$pluginName] ?? [];

                    // Extract enabled state from config
                    $isEnabled = $defaultConfig['enabled'] ?? false;

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

                    $this->command->info("✓ Seeded plugin: {$pluginName}" . ($isEnabled ? ' (enabled)' : ' (disabled)'));
                    $seededCount++;

                } catch (\Exception $e) {
                    $this->command->error("Failed to seed plugin '{$pluginName}': " . $e->getMessage());
                    Log::error("Failed to seed plugin config", [
                        'plugin' => $pluginName,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            $this->command->info("\n" . str_repeat('=', 50));
            $this->command->info("Plugin seeding completed!");
            $this->command->info("Seeded: {$seededCount} plugins");
            if ($skippedCount > 0) {
                $this->command->info("Skipped: {$skippedCount} existing plugins");
            }
            $this->command->info(str_repeat('=', 50));

        } catch (\Exception $e) {
            $this->command->error('Failed to seed plugin configurations: ' . $e->getMessage());
            Log::error('Plugin seeding failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Validate plugin configuration structure
     *
     * @param string $pluginName
     * @param array $config
     * @return bool
     */
    protected function validatePluginConfig(string $pluginName, array $config): bool
    {
        // Basic validation
        if (empty($config)) {
            $this->command->warn("Plugin '{$pluginName}' has empty configuration");
            return false;
        }

        // Check for required 'enabled' field
        if (!array_key_exists('enabled', $config)) {
            $this->command->warn("Plugin '{$pluginName}' missing 'enabled' field");
            return false;
        }

        return true;
    }

    /**
     * Clean and prepare configuration data
     *
     * @param array $config
     * @return array
     */
    protected function cleanConfigData(array $config): array
    {
        $cleaned = array_filter($config, function ($value) {
            return is_scalar($value) || is_array($value) || is_null($value);
        });

        return $cleaned;
    }
}

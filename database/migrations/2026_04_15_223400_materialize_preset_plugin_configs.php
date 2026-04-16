<?php

use App\Contracts\Agent\PluginRegistryInterface;
use App\Models\AiPreset;
use App\Models\PresetPluginConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 *
 * Materializes PresetPluginConfig records for every (preset, plugin) pair
 * using defaults from $plugin->getDefaultConfig() as the single source of truth.
 *
 * Why:
 *   Until now, PluginManager fell back to global PluginConfig records when a
 *   per-preset record was missing. After this migration, every preset has a
 *   full set of records, and the fallback can be safely removed in later.
 *
 * Idempotency:
 *   Uses PresetPluginConfig::findOrCreateForPreset(), which is itself a
 *   firstOrCreate. Running this migration multiple times is safe — existing
 *   records (including admin-edited configs) are never touched.
 *
 * What this migration does NOT do:
 *   - Does not drop the plugin_configs table
 *   - Does not migrate health_status/last_test_at data (those die with health system)
 *   - Does not modify any application code
 *
 * Down migration:
 *   Intentionally a no-op. Once records are created they may have been edited
 *   by admins, so blanket deletion would be destructive. If a real rollback is
 *   needed, do it manually with knowledge of what state to restore.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Resolve the plugin registry from the container so we get the
        // already-bootstrapped instance with all plugins registered via
        // AiServiceProvider. We do NOT instantiate plugins manually here —
        // that would skip DI and break plugins with constructor dependencies.
        /** @var PluginRegistryInterface $registry */
        $registry = app(PluginRegistryInterface::class);

        $plugins = $registry->allRegistered();

        if (empty($plugins)) {
            Log::warning('materialize_preset_plugin_configs: no plugins registered, skipping');
            return;
        }

        $presets = AiPreset::all();

        if ($presets->isEmpty()) {
            Log::info('materialize_preset_plugin_configs: no presets exist yet, nothing to materialize');
            return;
        }

        $createdCount = 0;
        $skippedCount = 0;

        // Wrap in a transaction so a partial failure leaves DB consistent.
        // findOrCreateForPreset uses firstOrCreate which respects unique constraints
        // (preset_id + plugin_name), so concurrent migrations are safe too.
        DB::transaction(function () use ($plugins, $presets, &$createdCount, &$skippedCount) {
            foreach ($presets as $preset) {
                foreach ($plugins as $plugin) {
                    $defaultConfig = $plugin->getDefaultConfig();

                    // findOrCreateForPreset returns existing record if present,
                    // creates with $defaultConfig otherwise. wasRecentlyCreated
                    // tells us which path was taken — useful for the summary log.
                    $record = PresetPluginConfig::findOrCreateForPreset(
                        $preset->getId(),
                        $plugin->getName(),
                        $defaultConfig
                    );

                    if ($record->wasRecentlyCreated) {
                        $createdCount++;
                    } else {
                        $skippedCount++;
                    }
                }
            }
        });

        Log::info('materialize_preset_plugin_configs: complete', [
            'presets_count'  => $presets->count(),
            'plugins_count'  => count($plugins),
            'created'        => $createdCount,
            'already_exists' => $skippedCount,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentional no-op. See class docblock.
        Log::info('materialize_preset_plugin_configs: down() is a no-op by design');
    }
};

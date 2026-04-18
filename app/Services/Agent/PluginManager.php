<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PluginExecutionContextBuilderInterface;
use App\Contracts\Agent\PluginManagerInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Models\AiPreset;
use App\Models\PresetPluginConfig;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

/**
 * Plugin manager — stateless, per-preset.
 *
 * Responsibilities:
 *   - Build PluginExecutionContext values via the context builder.
 *   - Materialize PresetPluginConfig rows on preset creation.
 *   - Read/update per-preset plugin config in a transactional manner.
 *
 * What it is NOT responsible for (anymore):
 *   - Holding a "current preset" — that concept is gone.
 *   - Mutating singleton plugin instances — plugins read everything
 *     from the context that's passed to each method call.
 *   - Health / testConnection — the whole health system was removed.
 *   - Global PluginConfig fallback — lives entirely on
 *     PresetPluginConfig. The old plugin_configs table may still
 *     exist in the DB as a zombie but nothing here reads it.
 */
class PluginManager implements PluginManagerInterface
{
    public function __construct(
        protected PluginRegistryInterface $registry,
        protected PresetPluginConfig $presetPluginConfigModel,
        protected DatabaseManager $db,
        protected LoggerInterface $logger,
        protected PluginExecutionContextBuilderInterface $contextBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function initializeConfigsForPreset(AiPreset $preset): void
    {
        $plugins = $this->registry->allRegistered();

        if (empty($plugins)) {
            return;
        }

        $this->db->transaction(function () use ($plugins, $preset) {
            foreach ($plugins as $plugin) {
                $this->presetPluginConfigModel::findOrCreateForPreset(
                    $preset->getId(),
                    $plugin->getName(),
                    $plugin->getDefaultConfig()
                );
            }
        });

        $this->logger->info('PluginManager: initialized plugin configs for preset', [
            'preset_id'    => $preset->getId(),
            'preset_name'  => $preset->getName(),
            'plugin_count' => count($plugins),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function buildContextFor(string $pluginName, AiPreset $preset): ?PluginExecutionContext
    {
        $plugin = $this->registry->get($pluginName);

        if (!$plugin) {
            return null;
        }

        return $this->contextBuilder->build($plugin, $preset);
    }

    /**
     * @inheritDoc
     *
     * just returns the singleton. Previously this method pushed
     * resolved config into the singleton via updateConfig()/setEnabled() —
     * not needed anymore since plugins read everything from the
     * PluginExecutionContext passed to each call.
     *
     * Kept in the interface because it's still a convenient "get the
     * plugin instance for this preset" entry point and callers using it
     * can migrate to explicit contextBuilder + plugin access over time.
     */
    public function configurePluginFor(string $pluginName, AiPreset $preset): ?CommandPluginInterface
    {
        return $this->registry->get($pluginName);
    }

    /**
     * @inheritDoc
     *
     * Returns enabled plugins together with their already-built contexts, so
     * downstream consumers (instruction builder, tool-schema builder) can
     * read plugin config without rebuilding it. Used for dynamic
     * getDescription/getInstructions/getToolSchema that vary per preset.
     */
    public function getEnabledPluginsWithContextsForPreset(AiPreset $preset): array
    {
        $enabled = [];

        foreach ($this->registry->allRegistered() as $plugin) {
            $context = $this->contextBuilder->build($plugin, $preset);

            if (!$context->enabled) {
                continue;
            }

            $enabled[$plugin->getName()] = [
                'plugin'  => $plugin,
                'context' => $context,
            ];
        }

        return $enabled;
    }

    /**
     * @inheritDoc
     */
    public function getEnabledPluginsForPreset(AiPreset $preset): array
    {
        $enabled = [];

        foreach ($this->registry->allRegistered() as $plugin) {
            $context = $this->contextBuilder->build($plugin, $preset);

            if (!$context->enabled) {
                continue;
            }

            $enabled[$plugin->getName()] = $plugin;
        }

        return $enabled;
    }

    /**
     * @inheritDoc
     */
    public function getAllPluginsInfoForPreset(AiPreset $preset): array
    {
        $info = [];

        foreach ($this->registry->allRegistered() as $plugin) {
            $info[$plugin->getName()] = $this->buildPluginInfoArray($plugin, $preset);
        }

        return $info;
    }

    /**
     * @inheritDoc
     */
    public function getPluginInfoForPreset(string $pluginName, AiPreset $preset): ?array
    {
        $plugin = $this->registry->get($pluginName);

        if (!$plugin) {
            return null;
        }

        return $this->buildPluginInfoArray($plugin, $preset);
    }

    /**
     * @inheritDoc
     */
    public function updatePluginConfigForPreset(string $pluginName, AiPreset $preset, array $config): array
    {
        $plugin = $this->registry->get($pluginName);

        if (!$plugin) {
            return [
                'success' => false,
                'errors'  => ['plugin' => "Plugin '{$pluginName}' not found"],
            ];
        }

        $errors = $plugin->validateConfig($config);
        if (!empty($errors)) {
            return [
                'success' => false,
                'errors'  => $errors,
            ];
        }

        try {
            return $this->db->transaction(function () use ($plugin, $pluginName, $preset, $config) {
                $record = $this->presetPluginConfigModel::findOrCreateForPreset(
                    $preset->getId(),
                    $pluginName,
                    $plugin->getDefaultConfig()
                );

                $record->updateConfig($config);

                return [
                    'success'   => true,
                    'config'    => $record->config_data,
                    'preset_id' => $preset->getId(),
                ];
            });
        } catch (\Throwable $e) {
            $this->logger->error('PluginManager: updatePluginConfigForPreset failed', [
                'plugin'    => $pluginName,
                'preset_id' => $preset->getId(),
                'error'     => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'errors'  => ['general' => 'Failed to update configuration: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function resetPluginConfigForPreset(string $pluginName, AiPreset $preset): array
    {
        $plugin = $this->registry->get($pluginName);

        if (!$plugin) {
            return [
                'success' => false,
                'errors'  => ['plugin' => "Plugin '{$pluginName}' not found"],
            ];
        }

        try {
            return $this->db->transaction(function () use ($plugin, $pluginName, $preset) {
                $record = $this->presetPluginConfigModel::findOrCreateForPreset(
                    $preset->getId(),
                    $pluginName,
                    $plugin->getDefaultConfig()
                );

                $record->resetToDefaults();

                return [
                    'success'   => true,
                    'config'    => $record->config_data,
                    'preset_id' => $preset->getId(),
                ];
            });
        } catch (\Throwable $e) {
            $this->logger->error('PluginManager: resetPluginConfigForPreset failed', [
                'plugin'    => $pluginName,
                'preset_id' => $preset->getId(),
                'error'     => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'errors'  => ['general' => $e->getMessage()],
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function setPluginEnabledForPreset(string $pluginName, AiPreset $preset, bool $enabled): array
    {
        $plugin = $this->registry->get($pluginName);

        if (!$plugin) {
            return [
                'success' => false,
                'errors'  => ['plugin' => "Plugin '{$pluginName}' not found"],
            ];
        }

        try {
            return $this->db->transaction(function () use ($plugin, $pluginName, $preset, $enabled) {
                $record = $this->presetPluginConfigModel::findOrCreateForPreset(
                    $preset->getId(),
                    $pluginName,
                    $plugin->getDefaultConfig()
                );

                $record->update(['is_enabled' => $enabled]);

                return [
                    'success'   => true,
                    'enabled'   => $enabled,
                    'preset_id' => $preset->getId(),
                ];
            });
        } catch (\Throwable $e) {
            $this->logger->error('PluginManager: setPluginEnabledForPreset failed', [
                'plugin'    => $pluginName,
                'preset_id' => $preset->getId(),
                'enabled'   => $enabled,
                'error'     => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'errors'  => ['general' => $e->getMessage()],
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getPluginStatisticsForPreset(AiPreset $preset): array
    {
        return $this->presetPluginConfigModel::getPresetStatistics($preset->getId());
    }

    /**
     * @inheritDoc
     */
    public function copyPluginConfigsBetweenPresets(int $fromPresetId, int $toPresetId): array
    {
        try {
            $copiedCount = $this->presetPluginConfigModel->copyBetweenPresets($fromPresetId, $toPresetId);

            $this->logger->info('Plugin configurations copied between presets', [
                'from_preset_id' => $fromPresetId,
                'to_preset_id'   => $toPresetId,
                'copied_count'   => $copiedCount,
            ]);

            return [
                'success'      => true,
                'copied_count' => $copiedCount,
                'message'      => "Copied {$copiedCount} plugin configurations",
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to copy plugin configurations between presets', [
                'from_preset_id' => $fromPresetId,
                'to_preset_id'   => $toPresetId,
                'error'          => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'errors'  => ['general' => $e->getMessage()],
            ];
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build the standard info array for one plugin in the scope of a preset.
     * Output shape mirrors the old getPluginInfo() so frontend stays compatible.
     */
    protected function buildPluginInfoArray(CommandPluginInterface $plugin, AiPreset $preset): array
    {
        $context = $this->contextBuilder->build($plugin, $preset);

        return [
            'name'              => $plugin->getName(),
            'description'       => $plugin->getDescription(),
            'enabled'           => $context->enabled,
            'config_fields'     => $plugin->getConfigFields(),
            'current_config'    => $context->config,
            'default_config'    => $plugin->getDefaultConfig(),
            'instructions'      => $plugin->getInstructions(),
            'available_methods' => $plugin->getAvailableMethods(),
            'preset_id'         => $preset->getId(),
            'preset_name'       => $preset->getName(),
        ];
    }

}

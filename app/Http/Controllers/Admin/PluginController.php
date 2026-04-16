<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginManagerFactoryInterface;
use App\Contracts\Agent\PluginManagerInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Plugin\CopyPluginConfigurationsRequest;
use App\Http\Requests\Admin\Plugin\UpdatePluginConfigRequest;
use App\Models\AiPreset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Psr\Log\LoggerInterface;

/**
 * Plugin management admin controller.
 *
 * The controller resolves PluginManager through the factory to avoid
 * circular-dependency issues in the service container.
 */
class PluginController extends Controller
{
    public function __construct(
        protected PluginManagerFactoryInterface $pluginManagerFactory,
        protected PluginRegistryInterface $pluginRegistry,
        protected PresetRegistryInterface $presetRegistry,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * Resolve the plugin manager (lazy, via factory).
     */
    protected function manager(): PluginManagerInterface
    {
        return $this->pluginManagerFactory->get();
    }

    /**
     * Resolve the target preset from an optional preset id.
     * Falls back to the default preset if no id is given.
     */
    protected function resolvePreset(?int $presetId): AiPreset
    {
        return $presetId
            ? $this->presetRegistry->getPreset($presetId)
            : $this->presetRegistry->getDefaultPreset();
    }

    /**
     * Display plugins management page for a specific preset.
     */
    public function index(Request $request, ?int $presetId = null): Response
    {
        try {
            $preset = $this->resolvePreset($presetId);
            $manager = $this->manager();

            $pluginsData = $manager->getAllPluginsInfoForPreset($preset);
            $statistics  = $manager->getPluginStatisticsForPreset($preset);

            $availablePresets = $this->presetRegistry->getActivePresets();

            $plugins = collect($pluginsData)->map(function ($pluginInfo, $pluginName) {
                return array_merge($pluginInfo, [
                    'name' => $pluginName,
                ]);
            })->values()->toArray();

            return Inertia::render('Admin/Plugins/Index', [
                'plugins' => $plugins,
                'statistics' => $statistics,
                'health_status' => $this->getStubHealthStatus(),
                'current_preset' => [
                    'id' => $preset->getId(),
                    'name' => $preset->getName(),
                    'description' => $preset->getDescription(),
                ],
                'available_presets' => $availablePresets->map(function ($p) {
                    return [
                        'id' => $p->getId(),
                        'name' => $p->getName(),
                        'description' => $p->getDescription(),
                        'engine_name' => $p->getEngineName(),
                    ];
                })->toArray(),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to load plugins page', [
                'preset_id' => $presetId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Inertia::render('Admin/Plugins/Index', [
                'plugins' => [],
                'statistics' => $this->getEmptyStatistics(),
                'health_status' => $this->getErrorHealthStatus(),
                'current_preset' => null,
                'available_presets' => [],
                'error' => 'Failed to load plugins: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Toggle plugin enabled/disabled state for a specific preset.
     */
    public function toggle(Request $request, string $pluginName, ?int $presetId = null): JsonResponse
    {
        try {
            $preset = $this->resolvePreset($presetId);
            $manager = $this->manager();

            $pluginsInfo = $manager->getAllPluginsInfoForPreset($preset);
            $currentPlugin = $pluginsInfo[$pluginName] ?? null;

            if (!$currentPlugin) {
                return response()->json([
                    'success' => false,
                    'message' => "Plugin '{$pluginName}' not found"
                ], 404);
            }

            $currentState = $currentPlugin['enabled'];
            $newState = !$currentState;

            $result = $manager->setPluginEnabledForPreset($pluginName, $preset, $newState);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => $newState
                        ? "Plugin '{$pluginName}' enabled for preset '{$preset->getName()}'"
                        : "Plugin '{$pluginName}' disabled for preset '{$preset->getName()}'",
                    'data' => [
                        'enabled' => $newState,
                        'preset_id' => $preset->getId(),
                        'preset_name' => $preset->getName(),
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle plugin',
                'errors' => $result['errors'] ?? []
            ], 400);

        } catch (\Exception $e) {
            $this->logger->error("Failed to toggle plugin for preset", [
                'plugin' => $pluginName,
                'preset_id' => $presetId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while toggling plugin',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update plugin configuration for a specific preset.
     */
    public function update(UpdatePluginConfigRequest $request, string $pluginName, ?int $presetId = null): JsonResponse
    {
        try {
            $preset = $this->resolvePreset($presetId);
            $config = $request->validated();

            $result = $this->manager()->updatePluginConfigForPreset($pluginName, $preset, $config);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => "Plugin '{$pluginName}' configuration updated for preset '{$preset->getName()}'",
                    'data' => [
                        'config' => $result['config'],
                        'connection_status' => $result['connection_status'] ?? null,
                        'preset_id' => $preset->getId(),
                        'preset_name' => $preset->getName(),
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to update plugin configuration',
                'errors' => $result['errors'] ?? []
            ], 422);

        } catch (\Exception $e) {
            $this->logger->error("Failed to update plugin config for preset", [
                'plugin' => $pluginName,
                'preset_id' => $presetId,
                'config' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating plugin configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset plugin configuration to defaults for a specific preset.
     */
    public function reset(Request $request, string $pluginName, ?int $presetId = null): JsonResponse
    {
        try {
            $preset = $this->resolvePreset($presetId);

            $result = $this->manager()->resetPluginConfigForPreset($pluginName, $preset);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => "Plugin '{$pluginName}' configuration reset to defaults for preset '{$preset->getName()}'",
                    'data' => [
                        'config' => $result['config'],
                        'preset_id' => $preset->getId(),
                        'preset_name' => $preset->getName(),
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to reset plugin configuration',
                'errors' => $result['errors'] ?? []
            ], 400);

        } catch (\Exception $e) {
            $this->logger->error("Failed to reset plugin config for preset", [
                'plugin' => $pluginName,
                'preset_id' => $presetId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while resetting plugin configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get plugin information for a specific preset.
     */
    public function show(string $pluginName, ?int $presetId = null): JsonResponse
    {
        try {
            $preset = $this->resolvePreset($presetId);

            $plugin = $this->manager()->getPluginInfoForPreset($pluginName, $preset);

            if (!$plugin) {
                return response()->json([
                    'success' => false,
                    'message' => "Plugin '{$pluginName}' not found"
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => array_merge($plugin, [
                    'name' => $pluginName,
                    'preset_id' => $preset->getId(),
                    'preset_name' => $preset->getName(),
                ])
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to get plugin info for preset", [
                'plugin' => $pluginName,
                'preset_id' => $presetId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving plugin information',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get plugin configuration schema.
     *
     * schema doesn't depend on a preset — it's a description of
     * the config fields the plugin declares in getConfigFields(). We read
     * directly from the plugin via the registry instead of going through
     * the deprecated PluginManager::getPluginConfigSchema().
     *
     * The $presetId parameter is kept for route compatibility but unused.
     */
    public function schema(string $pluginName, ?int $presetId = null): JsonResponse
    {
        try {
            $plugin = $this->pluginRegistry->get($pluginName);

            if (!$plugin) {
                return response()->json([
                    'success' => false,
                    'message' => "Plugin '{$pluginName}' not found"
                ], 404);
            }

            $schema = [
                'name'          => $plugin->getName(),
                'description'   => $plugin->getDescription(),
                'fields'        => $plugin->getConfigFields(),
                'default_config' => $plugin->getDefaultConfig(),
            ];

            return response()->json([
                'success' => true,
                'data' => $schema
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to get plugin schema", [
                'plugin' => $pluginName,
                'preset_id' => $presetId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving plugin schema',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Copy plugin configurations between presets.
     * Already preset-aware — takes two preset IDs explicitly.
     */
    public function copyConfigurations(CopyPluginConfigurationsRequest $request): JsonResponse
    {
        try {
            $result = $this->manager()->copyPluginConfigsBetweenPresets(
                $request->from_preset_id,
                $request->to_preset_id
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => $result['message'],
                    'data' => [
                        'copied_count' => $result['copied_count'],
                        'from_preset_id' => $request->from_preset_id,
                        'to_preset_id' => $request->to_preset_id,
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to copy plugin configurations',
                'errors' => $result['errors'] ?? []
            ], 400);

        } catch (\Exception $e) {
            $this->logger->error("Failed to copy plugin configurations", [
                'from_preset_id' => $request->from_preset_id,
                'to_preset_id' => $request->to_preset_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while copying plugin configurations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Empty statistics placeholder for error states.
     */
    protected function getEmptyStatistics(): array
    {
        return [
            'total_plugins' => 0,
            'enabled_plugins' => 0,
            'disabled_plugins' => 0,
        ];
    }

    /**
     * Neutral health-status stub used during the transition period.
     * will remove the health UI entirely, at which point this
     * method and its callers go away.
     */
    protected function getStubHealthStatus(): array
    {
        return [
            'overall_status' => 'ok',
            'plugins' => [],
        ];
    }

    /**
     * Error health-status shape for exception branches.
     */
    protected function getErrorHealthStatus(): array
    {
        return [
            'overall_status' => 'error',
            'plugins' => []
        ];
    }
}

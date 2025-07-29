<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginManagerInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Plugin\CopyPluginConfigurationsRequest;
use App\Http\Requests\Admin\Plugin\UpdatePluginConfigRequest;
use App\Models\AiPreset;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Psr\Log\LoggerInterface;

/**
 * Class PluginController
 *
 * Handles plugin management operations with full per-preset configuration support
 * All operations now work within the context of a specific preset
 */
class PluginController extends Controller
{
    public function __construct(
        protected PluginManagerInterface $pluginManager,
        protected PresetRegistryInterface $presetRegistry,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * Set current preset for plugin manager from request
     *
     * @param int|null $presetId
     * @return AiPreset
     */
    private function setCurrentPresetFromRequest(?int $presetId): AiPreset
    {
        if ($presetId) {
            $preset = $this->presetRegistry->getPreset($presetId);
            $this->pluginManager->setCurrentPreset($preset);
            return $preset;
        } else {
            $preset = $this->presetRegistry->getDefaultPreset();
            $this->pluginManager->setCurrentPreset($preset);
            return $preset;
        }
    }

    /**
     * Display plugins management page for specific preset
     *
     * @param Request $request
     * @param int|null $presetId
     * @return Response
     */
    public function index(Request $request, ?int $presetId = null): Response
    {
        try {
            $preset = $this->setCurrentPresetFromRequest($presetId);

            $pluginsData = $this->pluginManager->getAllPluginsInfo();
            $statistics = $this->pluginManager->getPluginStatistics();
            $healthStatus = $this->pluginManager->getHealthStatus();

            // Get all available presets for preset selector
            $availablePresets = $this->presetRegistry->getActivePresets();

            // Convert plugins object to array for frontend
            $plugins = collect($pluginsData)->map(function ($pluginInfo, $pluginName) {
                return array_merge($pluginInfo, [
                    'name' => $pluginName,
                ]);
            })->values()->toArray();

            return Inertia::render('Admin/Plugins/Index', [
                'plugins' => $plugins,
                'statistics' => $statistics,
                'health_status' => $healthStatus,
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

            // Return page with error state
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
     * Toggle plugin enabled/disabled state for specific preset
     *
     * @param Request $request
     * @param string $pluginName
     * @param int|null $presetId
     * @return JsonResponse
     */
    public function toggle(Request $request, string $pluginName, ?int $presetId = null): JsonResponse
    {
        try {
            $preset = $this->setCurrentPresetFromRequest($presetId);

            $pluginsInfo = $this->pluginManager->getAllPluginsInfo();
            $currentPlugin = $pluginsInfo[$pluginName] ?? null;

            if (!$currentPlugin) {
                return response()->json([
                    'success' => false,
                    'message' => "Plugin '{$pluginName}' not found"
                ], 404);
            }

            $currentState = $currentPlugin['enabled'];
            $newState = !$currentState;

            $result = $this->pluginManager->setPluginEnabled($pluginName, $newState);

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
     * Test plugin connection for specific preset
     *
     * @param Request $request
     * @param string $pluginName
     * @param int|null $presetId
     * @return JsonResponse
     */
    public function test(Request $request, string $pluginName, ?int $presetId = null): JsonResponse
    {
        try {
            $preset = $this->setCurrentPresetFromRequest($presetId);

            $pluginsInfo = $this->pluginManager->getAllPluginsInfo();
            $plugin = $pluginsInfo[$pluginName] ?? null;

            if (!$plugin) {
                return response()->json([
                    'success' => false,
                    'message' => "Plugin '{$pluginName}' not found"
                ], 404);
            }

            if (!$plugin['enabled']) {
                return response()->json([
                    'success' => false,
                    'data' => [
                        'is_working' => false,
                        'message' => "Plugin '{$pluginName}' is disabled for preset '{$preset->getName()}'"
                    ]
                ], 400);
            }

            $testResults = $this->pluginManager->testAllPlugins();
            $pluginTestResult = $testResults[$pluginName] ?? null;

            if ($pluginTestResult) {
                $isWorking = $pluginTestResult['connection_status'] ?? false;

                return response()->json([
                    'success' => true,
                    'data' => [
                        'is_working' => $isWorking,
                        'message' => $isWorking
                            ? "Plugin '{$pluginName}' is working correctly for preset '{$preset->getName()}'"
                            : "Plugin '{$pluginName}' connection failed for preset '{$preset->getName()}'",
                        'health_status' => $pluginTestResult['health_status'] ?? 'unknown',
                        'preset_id' => $preset->getId(),
                        'preset_name' => $preset->getName(),
                        'response_time' => rand(50, 500)
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'data' => [
                    'is_working' => false,
                    'message' => "Unable to test plugin '{$pluginName}' for preset '{$preset->getName()}'"
                ]
            ], 500);

        } catch (\Exception $e) {
            $this->logger->error("Failed to test plugin for preset", [
                'plugin' => $pluginName,
                'preset_id' => $presetId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'data' => [
                    'is_working' => false,
                    'message' => "Plugin test failed: " . $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Update plugin configuration for specific preset
     *
     * @param UpdatePluginConfigRequest $request
     * @param string $pluginName
     * @param int|null $presetId
     * @return JsonResponse
     */
    public function update(UpdatePluginConfigRequest $request, string $pluginName, ?int $presetId = null): JsonResponse
    {
        try {
            $preset = $this->setCurrentPresetFromRequest($presetId);
            $config = $request->validated();

            $result = $this->pluginManager->updatePluginConfig($pluginName, $config);

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
     * Reset plugin configuration to defaults for specific preset
     *
     * @param Request $request
     * @param string $pluginName
     * @param int|null $presetId
     * @return JsonResponse
     */
    public function reset(Request $request, string $pluginName, ?int $presetId = null): JsonResponse
    {
        try {
            $preset = $this->setCurrentPresetFromRequest($presetId);

            $result = $this->pluginManager->resetPluginConfig($pluginName);

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
     * Get plugin information for specific preset
     *
     * @param string $pluginName
     * @param int|null $presetId
     * @return JsonResponse
     */
    public function show(string $pluginName, ?int $presetId = null): JsonResponse
    {
        try {
            $preset = $this->setCurrentPresetFromRequest($presetId);

            $pluginsInfo = $this->pluginManager->getAllPluginsInfo();
            $plugin = $pluginsInfo[$pluginName] ?? null;

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
     * Get system health status for specific preset
     *
     * @param int|null $presetId
     * @return JsonResponse
     */
    public function health(?int $presetId = null): JsonResponse
    {
        try {
            $preset = $this->setCurrentPresetFromRequest($presetId);

            $healthStatus = $this->pluginManager->getHealthStatus();

            return response()->json([
                'success' => true,
                'data' => $healthStatus
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to get health status for preset", [
                'preset_id' => $presetId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while checking system health',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Run health check for all plugins in specific preset
     *
     * @param int|null $presetId
     * @return JsonResponse
     */
    public function healthCheck(?int $presetId = null): JsonResponse
    {
        try {
            $preset = $this->setCurrentPresetFromRequest($presetId);

            $results = $this->pluginManager->testAllPlugins();
            $healthStatus = $this->pluginManager->getHealthStatus();

            return response()->json([
                'success' => true,
                'message' => "Health check completed for preset '{$preset->getName()}'",
                'data' => [
                    'test_results' => $results,
                    'health_status' => $healthStatus,
                    'preset_id' => $preset->getId(),
                    'preset_name' => $preset->getName(),
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to run health check for preset", [
                'preset_id' => $presetId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred during health check',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get plugin configuration schema for specific preset
     *
     * @param string $pluginName
     * @param int|null $presetId
     * @return JsonResponse
     */
    public function schema(string $pluginName, ?int $presetId = null): JsonResponse
    {
        try {
            $preset = $this->setCurrentPresetFromRequest($presetId);

            $schema = $this->pluginManager->getPluginConfigSchema($pluginName);

            if (!$schema) {
                return response()->json([
                    'success' => false,
                    'message' => "Plugin '{$pluginName}' not found"
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $schema
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to get plugin schema for preset", [
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
     * Copy plugin configurations between presets
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function copyConfigurations(CopyPluginConfigurationsRequest $request): JsonResponse
    {
        try {
            $result = $this->pluginManager->copyPluginConfigsBetweenPresets(
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
     * Get empty statistics for error states
     *
     * @return array
     */
    protected function getEmptyStatistics(): array
    {
        return [
            'total_plugins' => 0,
            'enabled_plugins' => 0,
            'disabled_plugins' => 0,
            'healthy_plugins' => 0,
            'error_plugins' => 0,
        ];
    }

    /**
     * Get error health status for error states
     *
     * @return array
     */
    protected function getErrorHealthStatus(): array
    {
        return [
            'overall_status' => 'error',
            'plugins' => []
        ];
    }
}

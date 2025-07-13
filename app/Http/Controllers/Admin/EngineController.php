<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Models\{EngineRegistryInterface, PresetServiceInterface};
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Engine\{ValidateEngineConfigRequest, TestEngineWithConfigRequest};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\{Inertia, Response};
use Psr\Log\LoggerInterface;

/**
 * Controller for managing AI engines
 *
 * Thin controller that delegates to EngineRegistry for metadata and operations
 */
class EngineController extends Controller
{
    public function __construct(
        protected EngineRegistryInterface $engineRegistry,
        protected PresetServiceInterface $presetService,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * Display engines management page
     */
    public function index(): Response
    {
        try {
            $engines = $this->engineRegistry->getAvailableEngines();
            $stats = $this->engineRegistry->getEngineStats();

            return Inertia::render('Admin/Engines/Index', [
                'engines' => $engines,
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            $this->logger->error('EngineController: Failed to get engines', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Inertia::render('Admin/Engines/Index', [
                'engines' => [],
                'stats' => ['total_engines' => 0, 'available_engines' => 0]
            ])->with('error', 'Failed to load engines: ' . $e->getMessage());
        }
    }

    /**
     * Get engine information (API endpoint)
     */
    public function show(string $engineName): JsonResponse
    {
        try {
            $engines = $this->engineRegistry->getAvailableEngines();

            if (!isset($engines[$engineName])) {
                return $this->errorResponse('Engine not found', 404);
            }

            return $this->successResponse($engines[$engineName]);
        } catch (\Exception $e) {
            $this->logger->error('EngineController: Failed to get engine info', [
                'engine_name' => $engineName,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to retrieve engine information');
        }
    }

    /**
     * Get default configuration for specific engine (API endpoint)
     */
    public function getDefaults(string $engineName): JsonResponse
    {
        try {
            $defaults = $this->engineRegistry->getEngineDefaults($engineName);

            return $this->successResponse([
                'engine_name' => $engineName,
                'default_config' => $defaults
            ]);
        } catch (\Exception $e) {
            $this->logger->error('EngineController: Failed to get engine defaults', [
                'engine_name' => $engineName,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to retrieve engine defaults: ' . $e->getMessage());
        }
    }

    /**
     * Get configuration fields for specific engine (API endpoint)
     */
    public function getConfigFields(string $engineName, Request $request): JsonResponse
    {
        try {

            $presetConfig = $request->input('preset_config', []);

            $fields = $this->engineRegistry->getEngineConfigFields($engineName, $presetConfig);

            return $this->successResponse([
                'engine_name' => $engineName,
                'config_fields' => $fields
            ]);
        } catch (\Exception $e) {
            $this->logger->error('EngineController: Failed to get config fields', [
                'engine_name' => $engineName,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to retrieve configuration fields');
        }
    }

    /**
     * Get recommended presets for specific engine (API endpoint)
     */
    public function getRecommendedPresets(string $engineName): JsonResponse
    {
        try {
            $presets = $this->engineRegistry->getRecommendedPresets($engineName);

            return $this->successResponse([
                'engine_name' => $engineName,
                'recommended_presets' => $presets
            ]);
        } catch (\Exception $e) {
            $this->logger->error('EngineController: Failed to get recommended presets', [
                'engine_name' => $engineName,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to retrieve recommended presets');
        }
    }

    /**
     * Get available models for specific engine dynamically (API endpoint)
     */
    public function getAvailableModels(string $engineName, Request $request): JsonResponse
    {
        try {
            $config = $request->input('config', []);

            // Get engine instance to check capabilities
            $engine = $this->engineRegistry->createInstance($engineName, $config);

            if (!$engine) {
                return $this->errorResponse('Engine not found', 404);
            }

            // Check if engine supports dynamic models
            if (!method_exists($engine, 'supportsDynamicModels') || !$engine->supportsDynamicModels()) {
                return $this->errorResponse('Engine does not support dynamic model loading', 400);
            }

            // Validate API key if required
            if (method_exists($engine, 'requiresApiKeyForModels') &&
                $engine->requiresApiKeyForModels() &&
                empty($config['api_key'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'API key is required for this engine',
                    'requires_api_key' => true
                ], 400);
            }

            // Get available models
            $models = $engine->getAvailableModels($config);

            // Format models for frontend
            $formattedModels = $this->formatModelsForFrontend($models);

            return $this->successResponse([
                'engine_name' => $engineName,
                'models' => $formattedModels,
                'total_count' => count($formattedModels),
                'supports_dynamic_models' => true,
                'requires_api_key' => method_exists($engine, 'requiresApiKeyForModels') ?
                    $engine->requiresApiKeyForModels() : false
            ]);

        } catch (\Exception $e) {
            $this->logger->error('EngineController: Failed to load models', [
                'engine_name' => $engineName,
                'config_provided' => !empty($config),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('Failed to load models: ' . $e->getMessage());
        }
    }

    /**
     * Validate engine configuration (API endpoint)
     */
    public function validateConfig(ValidateEngineConfigRequest $request, string $engineName): JsonResponse
    {
        try {
            $config = $request->getConfig();
            $errors = $this->engineRegistry->validateEngineConfig($engineName, $config);

            return $this->successResponse([
                'engine_name' => $engineName,
                'is_valid' => empty($errors),
                'errors' => $errors
            ]);
        } catch (\Exception $e) {
            $this->logger->error('EngineController: Failed to validate config', [
                'engine_name' => $engineName,
                'config' => $request->getConfig(),
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to validate configuration: ' . $e->getMessage());
        }
    }

    /**
     * Test engine connection (API endpoint)
     */
    public function testConnection(string $engineName): JsonResponse
    {
        try {
            $testResult = $this->engineRegistry->testEngineConnection($engineName);

            return response()->json([
                'success' => $testResult['success'],
                'data' => array_merge([
                    'engine_name' => $engineName,
                    'connection_status' => $testResult['success'] ? 'connected' : 'failed'
                ], $testResult)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('EngineController: Failed to test connection', [
                'engine_name' => $engineName,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to test connection: ' . $e->getMessage());
        }
    }

    /**
     * Test engine with custom configuration (API endpoint)
     */
    public function testWithConfig(TestEngineWithConfigRequest $request, string $engineName): JsonResponse
    {
        try {
            $config = $request->getConfig();

            // First validate the config
            $errors = $this->engineRegistry->validateEngineConfig($engineName, $config);

            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configuration validation failed',
                    'data' => [
                        'engine_name' => $engineName,
                        'validation_errors' => $errors
                    ]
                ], 400);
            }

            // Test configuration using PresetService
            $testResult = $this->presetService->testEngineConfiguration($engineName, $config);

            return response()->json([
                'success' => $testResult['success'],
                'data' => array_merge([
                    'engine_name' => $engineName,
                    'config_used' => $config
                ], $testResult)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('EngineController: Failed to test with custom config', [
                'engine_name' => $engineName,
                'config' => $request->getConfig(),
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to test with configuration: ' . $e->getMessage());
        }
    }

    /**
     * Get engine statistics (API endpoint)
     */
    public function getStats(): JsonResponse
    {
        try {
            $stats = $this->engineRegistry->getEngineStats();
            return $this->successResponse($stats);
        } catch (\Exception $e) {
            $this->logger->error('EngineController: Failed to get stats', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to retrieve statistics');
        }
    }

    /**
     * Helper method for success responses
     */
    protected function successResponse($data = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Helper method for error responses
     */
    protected function errorResponse(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message
        ], $status);
    }

    /**
     * Format models for frontend consumption
     *
     * @param array $models
     * @return array
     */
    protected function formatModelsForFrontend(array $models): array
    {
        $formattedModels = [];

        foreach ($models as $modelId => $modelInfo) {

            $formattedModels[] = [
                'value' => $modelId,
                'label' => $modelInfo['display_name'] ?? $modelId,
                'source' => $modelInfo['source'] ?? 'api',
                'context_length' => $modelInfo['context_length'] ?? null,
            ];
        }

        return $formattedModels;
    }

}

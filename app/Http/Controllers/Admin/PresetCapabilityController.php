<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Capabilities\EmbeddingServiceInterface;
use App\Contracts\Agent\Capabilities\ListsModelsInterface;
use App\Contracts\Agent\Capabilities\ListsVoicesInterface;
use App\Contracts\Agent\Capabilities\SttServiceInterface;
use App\Contracts\Agent\Capabilities\TtsServiceInterface;
use App\Contracts\Agent\Capabilities\VisionServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Capabilities\UpdateCapabilityRequest;
use App\Models\AiPreset;
use App\Models\PresetCapabilityConfig;
use App\Services\Agent\Capabilities\Embedding\EmbeddingRegistry;
use App\Services\Agent\Capabilities\Speech\SttRegistry;
use App\Services\Agent\Capabilities\Speech\TtsRegistry;
use App\Services\Agent\Capabilities\Vision\DTO\ImageData;
use App\Services\Agent\Capabilities\Vision\VisionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Psr\Log\LoggerInterface;

/**
 * Manages capability provider configurations per preset.
 *
 * A single controller covers all capability types (embedding, image, audio, ...).
 * No separate CRUD per type — the driver's getConfigFields() drives the GUI form.
 */
class PresetCapabilityController extends Controller
{
    public function __construct(
        protected EmbeddingRegistry $embeddingRegistry,
        protected EmbeddingServiceInterface $embeddingService,
        protected VisionRegistry $visionRegistry,
        protected VisionServiceInterface $visionService,
        protected SttRegistry $sttRegistry,
        protected SttServiceInterface $sttService,
        protected TtsRegistry $ttsRegistry,
        protected TtsServiceInterface $ttsService,
        protected PresetRegistryInterface $presetRegistry,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Show the capabilities configuration page for a preset.
     */
    public function index(Request $request): Response
    {
        $presetId = $request->query('preset_id');

        try {
            $preset           = $this->resolvePreset($presetId);
            $availablePresets = $this->presetRegistry->getActivePresets();

            return Inertia::render('Admin/Capabilities/Index', [
                'capabilities'      => $this->buildCapabilitiesPayload($preset),
                'current_preset'    => $this->presetToArray($preset),
                'available_presets' => $availablePresets->map(fn ($p) => $this->presetToArray($p))->toArray(),
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('PresetCapabilityController::index error', [
                'preset_id' => $presetId,
                'error'     => $e->getMessage(),
            ]);

            return Inertia::render('Admin/Capabilities/Index', [
                'capabilities'      => [],
                'current_preset'    => null,
                'available_presets' => [],
                'error'             => 'Failed to load capabilities: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Save (create or update) a capability config for a preset.
     *
     * Request body:
     * {
     *   "driver":    "novita",
     *   "config":    { "api_key": "...", "model": "baai/bge-m3" },
     *   "is_active": true
     * }
     */
    public function update(UpdateCapabilityRequest $request, int $presetId, string $capability): JsonResponse
    {
        try {
            $preset   = $this->resolvePreset($presetId);
            $registry = $this->resolveRegistry($capability);

            $validated = $request->validated();

            if (!$registry->has($validated['driver'])) {
                return response()->json([
                    'success' => false,
                    'message' => "Driver '{$validated['driver']}' is not registered for capability '{$capability}'.",
                ], 422);
            }

            $provider = $registry->all()[$validated['driver']];
            $errors   = $provider->validateConfig($validated['config'] ?? []);

            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configuration validation failed.',
                    'errors'  => $errors,
                ], 422);
            }

            $existing      = PresetCapabilityConfig::forPreset($preset->id)
                ->forCapability($capability)
                ->first();

            $incomingConfig = $validated['config'] ?? [];
            $mergedConfig   = $this->mergeConfig(
                $existing?->config ?? [],
                $incomingConfig,
                $provider->getConfigFields()
            );

            $config = PresetCapabilityConfig::updateOrCreate(
                ['preset_id' => $preset->id, 'capability' => $capability],
                [
                    'driver'    => $validated['driver'],
                    'config'    => $mergedConfig,
                    'is_active' => $validated['is_active'] ?? true,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => ucfirst($capability) . ' capability configuration saved.',
                'data'    => [
                    'capability' => $capability,
                    'driver'     => $config->driver,
                    'is_active'  => $config->is_active,
                    'preset_id'  => $preset->id,
                ],
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('PresetCapabilityController::update error', [
                'preset_id'  => $presetId,
                'capability' => $capability,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while saving configuration.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List models available to the preset's CURRENTLY SAVED config for a
     * capability/driver, if the resolved provider supports enumeration.
     *
     * Uses the stored config (with the real api_key), not the form payload —
     * so the config must be saved first.
     */
    public function models(int $presetId, string $capability): JsonResponse
    {
        try {
            $preset   = $this->resolvePreset($presetId);
            $registry = $this->resolveRegistry($capability);

            $config = PresetCapabilityConfig::forPreset($preset->id)
                ->forCapability($capability)
                ->first();

            if ($config === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Save a configuration first, then load models.',
                ], 422);
            }

            $provider = $registry->makeFromConfig($config);

            if (!$provider instanceof ListsModelsInterface) {
                return response()->json([
                    'success' => false,
                    'message' => "Driver '{$config->driver}' does not support listing models.",
                ], 422);
            }

            $models = $provider->listModels();

            return response()->json([
                'success' => true,
                'driver'  => $config->driver,
                'models'  => $models,
                'count'   => count($models),
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('PresetCapabilityController::models error', [
                'preset_id'  => $presetId,
                'capability' => $capability,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load models: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test the current capability config for a preset.
     * Returns success flag, descriptive message, and latency.
     */
    public function test(int $presetId, string $capability): JsonResponse
    {
        try {
            $preset = $this->resolvePreset($presetId);
            $start  = microtime(true);

            $result = match ($capability) {
                'embedding' => $this->testEmbedding($preset),
                'vision'    => $this->testVision($preset),
                'stt' => $this->testStt($preset),
                'tts' => $this->testTts($preset),
                default     => ['success' => false, 'message' => "No test available for '{$capability}'."],
            };

            return response()->json(array_merge($result, [
                'latency_ms'  => round((microtime(true) - $start) * 1000, 1),
                'preset_id'   => $preset->id,
                'preset_name' => $preset->getName(),
            ]));

        } catch (\Throwable $e) {
            $this->logger->error('PresetCapabilityController::test error', [
                'preset_id'  => $presetId,
                'capability' => $capability,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Test failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the full capabilities payload sent to the Vue page.
     * Each capability entry includes registered drivers, their config field
     * definitions, and the current masked DB config.
     *
     * @return array<string, array>
     */
    private function buildCapabilitiesPayload(AiPreset $preset): array
    {
        $existing = PresetCapabilityConfig::forPreset($preset->id)
            ->get()
            ->keyBy('capability');

        return [
            'embedding' => $this->buildCapabilityEntry(
                capability:    'embedding',
                label:         'Embedding',
                description:   'Converts text into dense vectors for semantic memory search.',
                registry:      $this->embeddingRegistry,
                currentConfig: $existing->get('embedding'),
            ),
            'vision' => $this->buildCapabilityEntry(
                capability:    'vision',
                label:         'Vision',
                description:   'Turns images into text descriptions the agent can perceive.',
                registry:      $this->visionRegistry,
                currentConfig: $existing->get('vision'),
            ),
            'stt' => $this->buildCapabilityEntry(
                capability:    'stt',
                label:         'Speech-to-Text',
                description:   'Turns spoken audio into text the agent can perceive.',
                registry:      $this->sttRegistry,
                currentConfig: $existing->get('stt'),
            ),
            'tts' => $this->buildCapabilityEntry(
                capability:    'tts',
                label:         'Text-to-Speech',
                description:   'Gives the agent a voice.',
                registry:      $this->ttsRegistry,
                currentConfig: $existing->get('tts'),
            ),
        ];
    }

    /**
     * Build one capability entry for the Vue payload.
     */
    private function buildCapabilityEntry(
        string $capability,
        string $label,
        string $description,
        mixed  $registry,
        ?PresetCapabilityConfig $currentConfig,
    ): array {
        $drivers = [];
        foreach ($registry->all() as $driverName => $provider) {
            $drivers[$driverName] = [
                'name'           => $driverName,
                'display_name'   => $provider->getDisplayName(),
                'config_fields'  => $provider->getConfigFields(),
                'default_config' => $provider->getDefaultConfig(),
                'lists_models'   => $provider instanceof ListsModelsInterface,
                'lists_voices'   => $provider instanceof ListsVoicesInterface,
                'execution_mode' => method_exists($provider, 'getExecutionMode')
                    ? $provider->getExecutionMode()
                    : 'server',
                ];
        }

        return [
            'capability'     => $capability,
            'label'          => $label,
            'description'    => $description,
            'drivers'        => $drivers,
            'current_driver' => $currentConfig?->driver,
            'current_config' => $currentConfig
                ? $this->maskSensitiveConfig(
                    $currentConfig->config ?? [],
                    $drivers[$currentConfig->driver] ?? []
                )
                : null,
            'is_active'  => $currentConfig?->is_active ?? false,
            'configured' => $currentConfig !== null,
        ];
    }

    /**
     * Merge incoming config with existing, preserving real values for
     * password fields that arrived as the mask placeholder '••••••••'.
     */
    private function mergeConfig(array $existing, array $incoming, array $fields): array
    {
        $merged = $incoming;

        foreach ($fields as $key => $field) {
            if (($field['type'] ?? '') === 'password') {
                if (($incoming[$key] ?? '') === '••••••••' && !empty($existing[$key])) {
                    $merged[$key] = $existing[$key];
                }
            }
        }

        return $merged;
    }

    /**
     * Mask password fields before sending config to the frontend.
     */
    private function maskSensitiveConfig(array $config, array $driverMeta): array
    {
        $fields = $driverMeta['config_fields'] ?? [];
        $masked = $config;

        foreach ($fields as $key => $field) {
            if (($field['type'] ?? '') === 'password' && !empty($config[$key])) {
                $masked[$key] = '••••••••';
            }
        }

        return $masked;
    }

    /**
     * Resolve the registry for a capability type.
     * Extend the match when adding image, audio, etc.
     */
    private function resolveRegistry(string $capability): mixed
    {
        return match ($capability) {
            'embedding' => $this->embeddingRegistry,
            'vision'    => $this->visionRegistry,
            'stt'       => $this->sttRegistry,
            'tts'       => $this->ttsRegistry,
            default     => abort(404, "Unknown capability '{$capability}'."),
        };
    }

    /**
     * Resolve preset from ID or fall back to default.
     */
    private function resolvePreset(?int $presetId): AiPreset
    {
        return $presetId
            ? $this->presetRegistry->getPreset($presetId)
            : $this->presetRegistry->getDefaultPreset();
    }

    /**
     * Serialize preset to array for Inertia props.
     */
    private function presetToArray(AiPreset $preset): array
    {
        return [
            'id'          => $preset->getId(),
            'name'        => $preset->getName(),
            'description' => $preset->getDescription(),
            'engine_name' => $preset->getEngineName(),
        ];
    }

    /**
     * Run an embedding test for the preset's current config.
     */
    private function testEmbedding(AiPreset $preset): array
    {
        if (!$this->embeddingService->isAvailable($preset)) {
            return [
                'success' => false,
                'message' => 'No active embedding configuration for this preset.',
            ];
        }

        $vector = $this->embeddingService->embed('Embedding connection test.', $preset);

        if ($vector === null) {
            return [
                'success' => false,
                'message' => 'Embedding request failed. Check your API key and model.',
            ];
        }

        return [
            'success'   => true,
            'message'   => 'Embedding is working correctly.',
            'dimension' => count($vector),
        ];
    }

    /**
     * Run a vision test for the preset's current config.
     * Sends a tiny solid-colour PNG and asks for a one-word description.
     */
    private function testVision(AiPreset $preset): array
    {
        if (!$this->visionService->isAvailable($preset)) {
            return [
                'success' => false,
                'message' => 'No active vision configuration for this preset.',
            ];
        }

        $pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $image = new ImageData(
            base64:      $pngBase64,
            mimeType:    'image/png',
            sourceLabel: 'capability-test',
        );

        $description = $this->visionService->describe(
            $image,
            'Name the main color of this image in one word.',
            $preset
        );

        if ($description === null) {
            return [
                'success' => false,
                'message' => 'Vision request failed. Check your API key and model.',
            ];
        }

        return [
            'success'     => true,
            'message'     => 'Vision is working correctly.',
            'description' => mb_substr($description, 0, 200),
        ];
    }

    public function voices(Request $request, int $presetId, string $capability): JsonResponse
    {
        try {
            $preset   = $this->resolvePreset($presetId);
            $registry = $this->resolveRegistry($capability);

            $config = PresetCapabilityConfig::forPreset($preset->id)
                ->forCapability($capability)
                ->first();

            if ($config === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Save a configuration first, then load voices.',
                ], 422);
            }

            $provider = $registry->makeFromConfig($config);

            if (!$provider instanceof ListsVoicesInterface) {
                return response()->json([
                    'success' => false,
                    'message' => "Driver '{$config->driver}' does not support listing voices.",
                ], 422);
            }

            $voices = $provider->listVoices($request->query('model'));

            return response()->json([
                'success' => true,
                'driver'  => $config->driver,
                'voices'  => $voices,
                'count'   => count($voices),
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('PresetCapabilityController::voices error', [
                'preset_id'  => $presetId,
                'capability' => $capability,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load voices: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test TTS by synthesizing a short phrase. Client-side providers have
     * nothing to test on the server, and say so rather than failing.
     */
    private function testTts(AiPreset $preset): array
    {
        if (!$this->ttsService->isAvailable($preset)) {
            return [
                'success' => false,
                'message' => 'No active TTS configuration for this preset.',
            ];
        }

        if (!$this->ttsService->isServerSideAvailable($preset)) {
            return [
                'success' => true,
                'message' => 'This provider speaks in the browser — nothing to test on the server.',
            ];
        }

        $result = $this->ttsService->synthesizeResult('Speech synthesis test.', $preset);

        if (!$result->success) {
            return ['success' => false, 'message' => $result->error];
        }

        $kb = round($result->audio->sizeBytes() / 1024, 1);

        return [
            'success' => true,
            'message' => "Speech synthesis is working correctly ({$kb} KB of audio).",
        ];
    }

    /**
     * Test STT end-to-end: synthesize a phrase with TTS, then transcribe it back.
     *
     * A round trip is the only honest test here — STT needs real audio, and a
     * hardcoded sample would have to ship a file per language. Requires a working
     * server-side TTS config, which is stated plainly when it is missing.
     */
    private function testStt(AiPreset $preset): array
    {
        if (!$this->sttService->isAvailable($preset)) {
            return [
                'success' => false,
                'message' => 'No active STT configuration for this preset.',
            ];
        }

        if (!$this->sttService->isServerSideAvailable($preset)) {
            return [
                'success' => true,
                'message' => 'This provider listens in the browser — nothing to test on the server.',
            ];
        }

        if (!$this->ttsService->isServerSideAvailable($preset)) {
            return [
                'success' => false,
                'message' => 'Automatic testing needs a server-side TTS provider to generate '
                    . 'a sample. Configure TTS first, or test by speaking in the chat.',
            ];
        }

        $phrase = 'Speech recognition test.';
        $spoken = $this->ttsService->synthesizeResult($phrase, $preset);

        if (!$spoken->success) {
            return [
                'success' => false,
                'message' => 'Could not generate a test sample: ' . $spoken->error,
            ];
        }

        $result = $this->sttService->transcribeResult($spoken->audio, $preset);

        if (!$result->success) {
            return ['success' => false, 'message' => $result->error];
        }

        return [
            'success'    => true,
            'message'    => 'Speech recognition is working correctly.',
            'recognized' => mb_substr((string) $result->text, 0, 200),
        ];
    }

}

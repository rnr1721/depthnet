<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Preset\{CreatePresetRequest, UpdatePresetRequest, ValidatePresetConfigRequest, ImportRecommendedPresetRequest};
use App\Http\Resources\Admin\{PresetResource, PresetDetailResource};
use App\Exceptions\PresetException;
use Illuminate\Http\{JsonResponse, RedirectResponse};
use Inertia\{Inertia, Response};

/**
 * Controller for managing AI presets
 *
 * Thin controller that delegates business logic to PresetService
 */
class PresetController extends Controller
{
    public function __construct(
        protected PresetServiceInterface $presetService,
        protected EngineRegistryInterface $engineRegistry
    ) {
    }

    /**
     * Display presets management page
     */
    public function index(): Response
    {
        $presets = $this->presetService->getAllPresets();
        $engines = $this->engineRegistry->getAvailableEngines();

        return Inertia::render('Admin/Presets/Index', [
            'presets' => PresetResource::collection($presets)->toArray(request()),
            'engines' => $engines
        ]);
    }

    /**
     * Get single preset (API endpoint)
     */
    public function show(int $id): JsonResponse
    {
        try {
            $preset = $this->presetService->findById($id);

            if (!$preset) {
                return $this->errorResponse('Preset not found', 404);
            }

            $engineFields = $this->engineRegistry->getEngineConfigFields($preset->engine_name);
            $preset->engine_config_fields = $engineFields; // Add fields to model for resource

            return $this->successResponse(new PresetDetailResource($preset));

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve preset');
        }
    }

    /**
     * Create new preset
     */
    public function store(CreatePresetRequest $request): RedirectResponse
    {
        try {
            $this->presetService->createPresetWithValidation($request->validated());

            return redirect()
                ->route('admin.presets.index')
                ->with('success', 'Preset created successfully');

        } catch (PresetException $e) {
            return redirect()
                ->back()
                ->withErrors(['engine_config' => $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Update preset
     */
    public function update(UpdatePresetRequest $request, int $id): RedirectResponse
    {
        try {
            $this->presetService->updatePresetWithValidation($id, $request->validated());

            return redirect()
                ->route('admin.presets.index')
                ->with('success', 'Preset updated successfully');

        } catch (PresetException $e) {
            return redirect()
                ->back()
                ->withErrors(['engine_config' => $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Delete preset
     */
    public function destroy(int $id): RedirectResponse
    {
        try {
            $this->presetService->deletePresetWithValidation($id);

            return redirect()
                ->route('admin.presets.index')
                ->with('success', 'Preset deleted successfully');

        } catch (PresetException $e) {
            return redirect()
                ->back()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Set preset as default
     */
    public function setDefault(int $id): RedirectResponse
    {
        try {
            $this->presetService->setDefaultPresetWithLogging($id);

            return redirect()
                ->route('admin.presets.index')
                ->with('success', 'Default preset updated successfully');

        } catch (PresetException $e) {
            return redirect()
                ->back()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Duplicate preset
     */
    public function duplicate(int $id): RedirectResponse
    {
        try {
            $this->presetService->duplicatePreset($id);

            return redirect()
                ->route('admin.presets.index')
                ->with('success', 'Preset duplicated successfully');

        } catch (PresetException $e) {
            return redirect()
                ->back()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Test preset configuration (API endpoint)
     */
    public function testPreset(int $id): JsonResponse
    {
        try {
            $result = $this->presetService->testPreset($id);

            return $this->successResponse($result);

        } catch (PresetException $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }
    }

    /**
     * Validate preset configuration (API endpoint)
     */
    public function validatePresetConfig(ValidatePresetConfigRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $this->presetService->validateEngineConfigData($data['engine_name'], $data['engine_config']);

            return $this->successResponse([
                'engine_name' => $data['engine_name'],
                'is_valid' => true,
                'errors' => []
            ]);

        } catch (PresetException $e) {
            return $this->successResponse([
                'engine_name' => $data['engine_name'] ?? '',
                'is_valid' => false,
                'errors' => [$e->getMessage()]
            ]);
        }
    }

    /**
     * Import recommended preset (API endpoint)
     */
    public function importRecommended(ImportRecommendedPresetRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $preset = $this->presetService->importRecommendedPreset($data['engine_name'], $data['preset_index']);

            return $this->successResponse([
                'preset_id' => $preset->id,
                'preset_name' => $preset->name
            ]);

        } catch (PresetException $e) {
            return $this->errorResponse($e->getMessage(), 404);
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
}

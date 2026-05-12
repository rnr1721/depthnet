<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\PresetPluginDataServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PluginData\ReorderPluginDataRequest;
use App\Http\Requests\Admin\PluginData\StorePluginDataRequest;
use App\Http\Requests\Admin\PluginData\UpdatePluginDataRequest;
use Illuminate\Http\JsonResponse;

/**
 * CRUD controller for preset_plugin_data entries.
 *
 * All routes are scoped under /admin/presets/{presetId}/plugin-data/{pluginCode}.
 */
class PresetPluginDataController extends Controller
{
    public function __construct(
        protected PresetPluginDataServiceInterface $service,
        protected PresetServiceInterface $presetService,
    ) {
    }

    // ── GET /admin/presets/{presetId}/plugin-data/{pluginCode} ────────────────

    public function index(int $presetId, string $pluginCode): JsonResponse
    {
        $preset = $this->presetService->findByIdOrFail($presetId);

        $entries = $this->service->all($preset, $pluginCode)->values();

        return response()->json([
            'success' => true,
            'data'    => $entries,
        ]);
    }

    // ── POST /admin/presets/{presetId}/plugin-data/{pluginCode} ───────────────

    public function store(StorePluginDataRequest $request, int $presetId, string $pluginCode): JsonResponse
    {
        $preset = $this->presetService->findByIdOrFail($presetId);

        if ($this->service->find($preset, $pluginCode, $request->key)) {
            return response()->json([
                'success' => false,
                'message' => "Key '{$request->key}' already exists for this plugin.",
            ], 422);
        }

        $entry = $this->service->create(
            $preset,
            $pluginCode,
            $request->key,
            $request->value ?? '',
            $request->input('position', 0),
        );

        return response()->json(['success' => true, 'data' => $entry], 201);
    }

    // ── PUT /admin/presets/{presetId}/plugin-data/{pluginCode}/{id} ───────────

    public function update(UpdatePluginDataRequest $request, int $presetId, string $pluginCode, int $id): JsonResponse
    {
        $entry = $this->service->update($id, $request->validated());

        return response()->json(['success' => true, 'data' => $entry]);
    }

    // ── DELETE /admin/presets/{presetId}/plugin-data/{pluginCode}/{id} ────────

    public function destroy(int $presetId, string $pluginCode, int $id): JsonResponse
    {
        $this->service->delete($id);

        return response()->json(['success' => true]);
    }

    // ── POST /admin/presets/{presetId}/plugin-data/{pluginCode}/reorder ───────

    public function reorder(ReorderPluginDataRequest $request, int $presetId, string $pluginCode): JsonResponse
    {
        $this->service->reorder($request->ids);

        return response()->json(['success' => true]);
    }
}

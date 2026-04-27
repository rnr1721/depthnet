<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\PresetRagConfigServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RagConfig\ReorderRagConfigRequest;
use App\Http\Requests\Admin\RagConfig\StoreRagConfigRequest;
use App\Http\Requests\Admin\RagConfig\UpdateRagConfigRequest;
use Illuminate\Http\JsonResponse;

class PresetRagConfigController extends Controller
{
    public function __construct(
        protected PresetRagConfigServiceInterface $ragConfigService,
    ) {
    }

    public function index(int $presetId): JsonResponse
    {
        return response()->json(
            $this->ragConfigService->getOrdered($presetId)
        );
    }

    public function store(StoreRagConfigRequest $request, int $presetId): JsonResponse
    {
        try {
            $config = $this->ragConfigService->create($presetId, $request->validated());
            return response()->json($config, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(UpdateRagConfigRequest $request, int $presetId, int $configId): JsonResponse
    {
        $config = $this->ragConfigService->update($presetId, $configId, $request->validated());
        return response()->json($config);
    }

    public function destroy(int $presetId, int $configId): JsonResponse
    {
        $this->ragConfigService->delete($presetId, $configId);
        return response()->json(['ok' => true]);
    }

    public function reorder(ReorderRagConfigRequest $request, int $presetId): JsonResponse
    {
        $this->ragConfigService->reorder($presetId, $request->validated('ids'));
        return response()->json(['ok' => true]);
    }
}

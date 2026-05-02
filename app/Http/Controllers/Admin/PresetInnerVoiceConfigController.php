<?php

// ── App/Http/Controllers/Admin/PresetInnerVoiceConfigController.php ───────────

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\PresetInnerVoiceConfigServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InnerVoiceConfig\ReorderInnerVoiceConfigRequest;
use App\Http\Requests\Admin\InnerVoiceConfig\StoreInnerVoiceConfigRequest;
use App\Http\Requests\Admin\InnerVoiceConfig\UpdateInnerVoiceConfigRequest;
use Illuminate\Http\JsonResponse;

class PresetInnerVoiceConfigController extends Controller
{
    public function __construct(
        protected PresetInnerVoiceConfigServiceInterface $configService,
    ) {
    }

    public function index(int $presetId): JsonResponse
    {
        return response()->json(
            $this->configService->getOrdered($presetId)
        );
    }

    public function store(StoreInnerVoiceConfigRequest $request, int $presetId): JsonResponse
    {
        try {
            $config = $this->configService->create($presetId, $request->validated());
            return response()->json($config, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(UpdateInnerVoiceConfigRequest $request, int $presetId, int $configId): JsonResponse
    {
        $config = $this->configService->update($presetId, $configId, $request->validated());
        return response()->json($config);
    }

    public function destroy(int $presetId, int $configId): JsonResponse
    {
        $this->configService->delete($presetId, $configId);
        return response()->json(['ok' => true]);
    }

    public function reorder(ReorderInnerVoiceConfigRequest $request, int $presetId): JsonResponse
    {
        $this->configService->reorder($presetId, $request->validated('ids'));
        return response()->json(['ok' => true]);
    }
}

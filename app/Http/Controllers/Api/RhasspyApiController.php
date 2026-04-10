<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Chat\ChatServiceInterface;
use App\Contracts\Integrations\Rhasspy\RhasspyServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Psr\Log\LoggerInterface;

class RhasspyApiController extends Controller
{
    public function __construct(
        private readonly PresetServiceInterface $presetService,
        private readonly RhasspyServiceInterface $rhasspyService,
        private readonly ChatServiceInterface $chatService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * POST /api/v1/rhasspy/presets/{preset_id}/speech
     */
    public function speech(Request $request, int $preset_id): JsonResponse
    {
        $preset = $this->presetService->findById($preset_id);
        if (!$preset) {
            return response()->json(['error' => 'Preset not found'], 404);
        }

        if (!$this->rhasspyService->isIncomingEnabledForPreset($preset)) {
            return response()->json(['error' => 'Rhasspy incoming not enabled for this preset'], 403);
        }

        $token = $this->extractToken($request);
        if (!$this->rhasspyService->validateIncomingToken($preset, $token)) {
            $this->logger->warning('RhasspyApiController: invalid token', [
                'preset_id' => $preset_id,
                'ip'        => $request->ip(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $text = trim($request->input('text', ''));
        if (empty($text)) {
            return response()->json(['error' => 'No text in payload'], 422);
        }

        try {
            $this->chatService->sendVoiceInput(
                presetId: $preset_id,
                content:  $text,
                source:   'rhasspy',
            );

            $this->logger->info('RhasspyApiController: speech received', [
                'preset_id'   => $preset_id,
                'text_length' => mb_strlen($text),
            ]);

            return response()->json(['status' => 'ok']);

        } catch (\Throwable $e) {
            $this->logger->error('RhasspyApiController: failed to forward message', [
                'preset_id' => $preset_id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Internal error'], 500);
        }
    }

    /**
     * GET /api/v1/rhasspy/presets/{preset_id}/ping
     */
    public function ping(Request $request, int $preset_id): JsonResponse
    {
        $preset = $this->presetService->findById($preset_id);
        if (!$preset) {
            return response()->json(['error' => 'Preset not found'], 404);
        }

        if (!$this->rhasspyService->isIncomingEnabledForPreset($preset)) {
            return response()->json(['error' => 'Rhasspy incoming not enabled'], 403);
        }

        $token = $this->extractToken($request);
        if (!$this->rhasspyService->validateIncomingToken($preset, $token)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json([
            'status'      => 'ok',
            'preset_id'   => $preset_id,
            'preset_name' => $preset->getName(),
        ]);
    }

    private function extractToken(Request $request): string
    {
        return $request->bearerToken() ?? (string) $request->query('token', '');
    }
}

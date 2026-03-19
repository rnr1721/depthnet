<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Chat\ChatServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Chat\GetMessagesRequest;
use App\Http\Requests\Api\Chat\PoolInputRequest;
use App\Http\Requests\Api\Chat\SendMessageRequest;
use App\Models\Message;
use Illuminate\Http\JsonResponse;

class ChatApiController extends Controller
{
    public function __construct(
        protected ChatServiceInterface $chatService,
        protected ChatStatusServiceInterface $chatStatusService,
        protected PresetRegistryInterface $presetRegistry,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────
    // GET /api/v1/chat/presets/{preset_id}/messages
    // ──────────────────────────────────────────────────────────────────

    public function messages(GetMessagesRequest $request, int $preset_id): JsonResponse
    {
        $this->validatePresetExists($preset_id);

        $page    = (int) ($request->validated('page', 1));
        $perPage = (int) ($request->validated('per_page', 30));

        $result = $this->chatService->getMessagesPaginatedEnhanced($preset_id, $page, $perPage);

        return response()->json([
            'data'       => collect($result['messages'])->map(fn ($m) => $this->formatMessage($m)),
            'pagination' => $result['pagination'],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // POST /api/v1/chat/presets/{preset_id}/messages
    // ──────────────────────────────────────────────────────────────────

    public function sendMessage(SendMessageRequest $request, int $preset_id): JsonResponse
    {
        $this->validatePresetExists($preset_id);

        $dispatch = !$this->chatStatusService->getPresetStatus($preset_id);

        $message = $this->chatService->sendUserMessage(
            user:     $request->user(),
            presetId: $preset_id,
            content:  $request->validated('content'),
            dispatch: $dispatch,
        );

        return response()->json([
            'message' => $this->formatMessage($message),
        ], 201);
    }

    // ──────────────────────────────────────────────────────────────────
    // POST /api/v1/chat/presets/{preset_id}/pool   (admin only)
    // ──────────────────────────────────────────────────────────────────

    public function poolInput(PoolInputRequest $request, int $preset_id): JsonResponse
    {
        $this->validatePresetExists($preset_id);

        try {
            $result = $this->chatService->sendApiInput(
                user:       $request->user(),
                presetId:   $preset_id,
                sourceName: $request->validated('source'),
                content:    $request->validated('content'),
                dispatch:   (bool) $request->validated('dispatch', false),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if ($result instanceof Message) {
            return response()->json([
                'dispatched' => true,
                'message'    => $this->formatMessage($result),
            ], 201);
        }

        return response()->json($result);
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function validatePresetExists(int $presetId): mixed
    {
        try {
            return $this->presetRegistry->getPreset($presetId);
        } catch (\Throwable) {
            abort(response()->json(['error' => "Preset #{$presetId} not found."], 404));
        }
    }

    private function formatMessage(mixed $message): array
    {
        return [
            'id'         => $message->id,
            'role'       => $message->role,
            'content'    => $message->content,
            'preset_id'  => $message->preset_id,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}

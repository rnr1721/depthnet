<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Integrations\Telegram\TelegramServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Telegram\AuthCodeRequest;
use App\Http\Requests\Admin\Telegram\AuthInitRequest;
use App\Http\Requests\Admin\Telegram\AuthPasswordRequest;
use App\Http\Requests\Admin\Telegram\AuthPhoneRequest;
use Illuminate\Http\JsonResponse;

/**
 * TelegramController
 *
 * Thin controller — delegates all logic to TelegramServiceInterface.
 */
class TelegramController extends Controller
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected PresetServiceInterface $presetService
    ) {
    }

    /**
     * Get Telegram session status for a preset.
     */
    public function status(int $presetId): JsonResponse
    {
        $this->presetService->findByIdOrFail($presetId);

        return $this->ok($this->telegram->getStatus($presetId));
    }

    /**
     * Step 0: save API credentials.
     */
    public function authInit(AuthInitRequest $request, int $presetId): JsonResponse
    {
        $this->presetService->findByIdOrFail($presetId);

        $result = $this->telegram->authInit($presetId, $request->api_id, $request->api_hash);

        return $result['success'] ? $this->ok($result) : $this->fail($result['message']);
    }

    /**
     * Step 1: send confirmation code to the phone number.
     */
    public function authPhone(AuthPhoneRequest $request, int $presetId): JsonResponse
    {
        $this->presetService->findByIdOrFail($presetId);

        $result = $this->telegram->authPhone($presetId, $request->phone);

        return $result['success'] ? $this->ok($result) : $this->fail($result['message']);
    }

    /**
     * Step 2: submit the confirmation code.
     */
    public function authCode(AuthCodeRequest $request, int $presetId): JsonResponse
    {
        $this->presetService->findByIdOrFail($presetId);

        $result = $this->telegram->authCode($presetId, $request->code);

        return $result['success'] ? $this->ok($result) : $this->fail($result['message']);
    }

    /**
     * Step 3: submit 2FA password.
     */
    public function authPassword(AuthPasswordRequest $request, int $presetId): JsonResponse
    {
        $this->presetService->findByIdOrFail($presetId);

        $result = $this->telegram->authPassword($presetId, $request->password);

        return $result['success'] ? $this->ok($result) : $this->fail($result['message']);
    }

    /**
     * Delete session files for a preset.
     */
    public function destroySession(int $presetId): JsonResponse
    {
        $this->presetService->findByIdOrFail($presetId);

        $this->telegram->destroySession($presetId);

        return $this->ok(['message' => 'Session removed.']);
    }

    // -- Helpers --------------------------------------------------------------

    private function ok(array $data = []): JsonResponse
    {
        return response()->json(['success' => true, ...$data]);
    }

    private function fail(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}

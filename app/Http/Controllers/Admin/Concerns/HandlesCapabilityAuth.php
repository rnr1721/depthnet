<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Contracts\Agent\Capabilities\AuthenticatesInteractivelyInterface;
use App\Http\Requests\Admin\Capabilities\CapabilityAuthRequest;
use App\Models\PresetCapabilityConfig;
use Illuminate\Http\JsonResponse;

/**
 * Interactive login endpoints for capability providers that implement
 * AuthenticatesInteractivelyInterface (Telegram user accounts today; any
 * QR/phone/OTP-style login tomorrow).
 *
 * Generic on purpose: nothing here knows about Telegram. The provider drives
 * the flow through AuthStep objects, the frontend renders whatever fields a
 * step declares.
 *
 * Mixed into PresetCapabilityController; relies on its resolvePreset(),
 * resolveRegistry() and $logger.
 *
 * Like models()/voices(), these work from the SAVED config — active or not —
 * so the operator can log in before switching the capability on.
 *
 *   GET    /admin/capabilities/{presetId}/{capability}/auth   → current step (beginAuth)
 *   POST   /admin/capabilities/{presetId}/{capability}/auth   → submit {step, input}
 *   DELETE /admin/capabilities/{presetId}/{capability}/auth   → logout
 */
trait HandlesCapabilityAuth
{
    public function authShow(int $presetId, string $capability): JsonResponse
    {
        $provider = $this->authProvider($presetId, $capability);
        if ($provider instanceof JsonResponse) {
            return $provider;
        }

        try {
            return response()->json(['success' => true, 'step' => $provider->beginAuth()->toArray()]);
        } catch (\Throwable $e) {
            return $this->authFailure('authShow', $presetId, $capability, $e);
        }
    }

    public function authSubmit(CapabilityAuthRequest $request, int $presetId, string $capability): JsonResponse
    {
        $provider = $this->authProvider($presetId, $capability);
        if ($provider instanceof JsonResponse) {
            return $provider;
        }

        try {
            $step = $provider->submitAuth(
                (string) $request->validated('step'),
                (array) ($request->validated('input') ?? []),
            );

            // A rejected input is a normal outcome (wrong code, bad phone) —
            // 200 with the step carrying ->error, not an HTTP error.
            return response()->json(['success' => true, 'step' => $step->toArray()]);
        } catch (\Throwable $e) {
            // Never log $request->input(): it may contain a 2FA password.
            return $this->authFailure('authSubmit', $presetId, $capability, $e);
        }
    }

    public function authDestroy(int $presetId, string $capability): JsonResponse
    {
        $provider = $this->authProvider($presetId, $capability);
        if ($provider instanceof JsonResponse) {
            return $provider;
        }

        try {
            $provider->logout();
            return response()->json(['success' => true, 'step' => $provider->beginAuth()->toArray()]);
        } catch (\Throwable $e) {
            return $this->authFailure('authDestroy', $presetId, $capability, $e);
        }
    }

    /**
     * Provider bound to the preset's saved config, or a ready-to-return error.
     */
    private function authProvider(int $presetId, string $capability): AuthenticatesInteractivelyInterface|JsonResponse
    {
        $preset   = $this->resolvePreset($presetId);
        $registry = $this->resolveRegistry($capability);

        $config = PresetCapabilityConfig::forPreset($preset->id)
            ->forCapability($capability)
            ->first();

        if ($config === null) {
            return response()->json([
                'success' => false,
                'message' => 'Save the configuration first, then log in.',
            ], 422);
        }

        try {
            $provider = $registry->makeFromConfig($config);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if (!$provider instanceof AuthenticatesInteractivelyInterface) {
            return response()->json([
                'success' => false,
                'message' => "Driver '{$config->driver}' does not use an interactive login.",
            ], 422);
        }

        return $provider;
    }

    private function authFailure(string $action, int $presetId, string $capability, \Throwable $e): JsonResponse
    {
        $this->logger->error("PresetCapabilityController::{$action} error", [
            'preset_id'  => $presetId,
            'capability' => $capability,
            'error'      => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Login request failed: ' . $e->getMessage(),
        ], 500);
    }
}

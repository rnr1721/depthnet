<?php

namespace App\Http\Controllers;

use App\Contracts\ApiKeys\ApiKeyServiceInterface;
use App\Http\Requests\ApiKey\CreateApiKeyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles API key management for the authenticated user's profile.
 * Routes live under /profile/api-keys (web, session-auth).
 */
class ApiKeyController extends Controller
{
    public function __construct(
        protected ApiKeyServiceInterface $apiKeyService,
    ) {
    }

    /**
     * GET /profile/api-keys
     * Return all keys for the current user.
     */
    public function index(Request $request): JsonResponse
    {
        $keys = $this->apiKeyService->listKeys($request->user());

        return response()->json([
            'keys' => $keys->map(fn ($k) => [
                'id'           => $k->id,
                'name'         => $k->name,
                'key_prefix'   => $k->key_prefix,
                'is_active'    => $k->isActive(),
                'last_used_at' => $k->last_used_at?->toIso8601String(),
                'revoked_at'   => $k->revoked_at?->toIso8601String(),
                'created_at'   => $k->created_at->toIso8601String(),
            ]),
            'limit' => ApiKeyServiceInterface::MAX_KEYS_PER_USER,
        ]);
    }

    /**
     * POST /profile/api-keys
     * Create a new key. The full plaintext is returned ONCE.
     */
    public function store(CreateApiKeyRequest $request): JsonResponse
    {
        try {
            ['plaintext' => $plaintext, 'apiKey' => $apiKey] =
                $this->apiKeyService->createKey($request->user(), $request->input('name'));

            return response()->json([
                'message' => 'API key created. Copy it now — it will not be shown again.',
                'key'     => $plaintext,          // shown once
                'meta'    => [
                    'id'         => $apiKey->id,
                    'name'       => $apiKey->name,
                    'key_prefix' => $apiKey->key_prefix,
                    'created_at' => $apiKey->created_at->toIso8601String(),
                ],
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE /profile/api-keys/{id}
     * Revoke a key. Soft-delete: sets revoked_at, does not remove the row.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->apiKeyService->revokeKey($request->user(), $id);

            return response()->json(['message' => 'API key revoked successfully.']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['error' => 'Key not found or already revoked.'], 404);
        }
    }
}

<?php

namespace App\Http\Middleware;

use App\Contracts\ApiKeys\ApiKeyServiceInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyMiddleware
{
    public function __construct(
        protected ApiKeyServiceInterface $apiKeyService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (!$bearer) {
            return response()->json([
                'error' => 'API key missing. Provide it as: Authorization: Bearer <key>',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $apiKey = $this->apiKeyService->findByPlaintext($bearer);

        if (!$apiKey) {
            return response()->json([
                'error' => 'Invalid or revoked API key.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Attach user to the request so controllers can call $request->user()
        auth()->setUser($apiKey->user);

        // Update last_used_at without touching updated_at
        $apiKey->touchUsed();

        // Make the resolved key available to controllers if needed
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}

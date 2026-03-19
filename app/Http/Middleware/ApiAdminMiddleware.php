<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Must be applied AFTER ApiKeyMiddleware (user is already resolved).
 */
class ApiAdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->is_admin) {
            return response()->json([
                'error' => 'This endpoint requires administrator privileges.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}

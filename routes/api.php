<?php

use App\Http\Controllers\Api\ChatApiController;
use App\Http\Middleware\ApiKeyMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware(ApiKeyMiddleware::class)->group(function () {

    // ── Chat endpoints (any authenticated user) ──────────────────────────
    Route::prefix('v1/chat')->name('api.chat.')->group(function () {

        /**
         * GET /api/v1/chat/presets/{preset_id}/messages
         * Query params: page (int), per_page (int, max 100)
         */
        Route::get('presets/{preset_id}/messages', [ChatApiController::class, 'messages'])
            ->name('messages');

        /**
         * POST /api/v1/chat/presets/{preset_id}/messages
         * Body: { "content": "Hello!" }
         */
        Route::post('presets/{preset_id}/messages', [ChatApiController::class, 'sendMessage'])
            ->name('send');
    });

    // ── Input-pool endpoints (admin only) ────────────────────────────────
    Route::prefix('v1/chat')->name('api.pool.')->middleware('api_admin')->group(function () {

        /**
         * POST /api/v1/chat/presets/{preset_id}/pool
         * Body: { "source": "weather_bot", "content": "Sunny, 22°C", "dispatch": false }
         *
         * dispatch=false → add to pool only
         * dispatch=true  → add to pool AND flush → send serialised JSON to model
         */
        Route::post('presets/{preset_id}/pool', [ChatApiController::class, 'poolInput'])
            ->name('pool');
    });
});

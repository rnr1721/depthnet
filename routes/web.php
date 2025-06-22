<?php

use App\Http\Controllers\Admin\EngineController;
use App\Http\Controllers\Admin\MemoryController;
use App\Http\Controllers\Admin\PluginController;
use App\Http\Controllers\Admin\PresetController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VectorMemoryController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WelcomeController;
use App\Http\Middleware\AdminMiddleware;
use Illuminate\Support\Facades\Route;

// Mainpage route
Route::get('/', [WelcomeController::class,'index'])->name('home');

// Authentication routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegistrationForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/forgot-password', [AuthController::class, 'showForgotPasswordForm'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLinkEmail'])->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPasswordForm'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
});

// Routes for authenticated users
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Chat routes
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/', [ChatController::class, 'index'])->name('index');
        Route::post('message', [ChatController::class, 'sendMessage'])->name('message');
        Route::delete('message/{messageId}', [ChatController::class, 'deleteMessage'])->name('delete-message');
        Route::post('clear', [ChatController::class, 'clearHistory'])->name('clear');
        Route::get('new-messages/{lastId?}', [ChatController::class, 'getNewMessages'])->name('new-messages');
        Route::post('preset-settings', [ChatController::class, 'updatePresetSettings'])->name('preset-settings');
        Route::post('export', [ChatController::class, 'exportChat'])->middleware(AdminMiddleware::class)->name('export');
        Route::put('preset/{id}', [ChatController::class, 'updatePreset'])->middleware(AdminMiddleware::class)->name('preset.update');
        Route::get('users', [ChatController::class, 'getUsers'])->name('users');
    });

    // Profile routes
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::put('/', [ProfileController::class, 'update'])->name('update');
        Route::put('password', [ProfileController::class, 'updatePassword'])->name('password');
    });

    // Admin routes
    Route::prefix('admin')->middleware(AdminMiddleware::class)->name('admin.')->group(function () {
        Route::get('settings', [SettingsController::class, 'index'])
        ->name('settings');
        Route::post('save-options', [SettingsController::class, 'saveOptions'])->name('save-options');

        // Users management routes
        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/', [UserController::class, 'index'])->name('index');
            Route::get('/export', [UserController::class, 'export'])->name('export');
            Route::post('/', [UserController::class, 'store'])->name('store');
            Route::put('/{user}', [UserController::class, 'update'])->name('update');
            Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');
            Route::patch('/{user}/toggle-admin', [UserController::class, 'toggleAdmin'])->name('toggle-admin');

        });

        // AI Presets management routes
        Route::prefix('presets')->name('presets.')->group(function () {
            Route::get('/', [PresetController::class, 'index'])->name('index');
            Route::post('/', [PresetController::class, 'store'])->name('store');
            Route::get('/{id}', [PresetController::class, 'show'])->name('show');
            Route::put('/{id}', [PresetController::class, 'update'])->name('update');
            Route::delete('/{id}', [PresetController::class, 'destroy'])->name('destroy');
            Route::post('/{id}/set-default', [PresetController::class, 'setDefault'])->name('set-default');
            Route::get('/{id}/duplicate', [PresetController::class, 'duplicate'])->name('duplicate');
        });

        // AI Engines management routes
        Route::prefix('engines')->name('engines.')->group(function () {
            Route::get('/', [EngineController::class, 'index'])->name('index');
            Route::get('/{engineName}/defaults', [EngineController::class, 'getDefaults'])->name('defaults');
            Route::post('/{engineName}/validate', [EngineController::class, 'validateConfig'])->name('validate');
            Route::get('/{engineName}/test', [EngineController::class, 'testConnection'])->name('test');
            Route::get('/{engineName}/config-fields', [EngineController::class, 'getConfigFields'])->name('config-fields');
            Route::get('/{engineName}/recommended-presets', [EngineController::class, 'getRecommendedPresets'])->name('recommended-presets');
            Route::post('/{engineName}/test-config', [EngineController::class, 'testWithConfig'])->name('test-config');
            Route::get('/{engineName}/info', [EngineController::class, 'show'])->name('info');
        });

        // Command Plugin management routes
        Route::prefix('plugins')->name('plugins.')->group(function () {
            Route::get('/', [PluginController::class, 'index'])->name('index');
            Route::get('/health', [PluginController::class, 'health'])->name('health');
            Route::post('/health-check', [PluginController::class, 'healthCheck'])->name('health-check');

            Route::prefix('{pluginName}')->group(function () {
                Route::get('/', [PluginController::class, 'show'])->name('show');
                Route::post('/toggle', [PluginController::class, 'toggle'])->name('toggle');
                Route::post('/test', [PluginController::class, 'test'])->name('test');
                Route::post('/update', [PluginController::class, 'update'])->name('update');
                Route::post('/reset', [PluginController::class, 'reset'])->name('reset');
            });
        });

        // Memory Management routes
        Route::prefix('memory')->name('memory.')->group(function () {
            Route::get('/', [MemoryController::class, 'index'])->name('index');
            Route::post('/', [MemoryController::class, 'store'])->name('store');
            Route::put('/{itemId}', [MemoryController::class, 'update'])->name('update');
            Route::delete('/{itemNumber}', [MemoryController::class, 'destroy'])->name('destroy');
            Route::post('/clear', [MemoryController::class, 'clear'])->name('clear');
            Route::post('/search', [MemoryController::class, 'search'])->name('search');
            Route::get('/export', [MemoryController::class, 'export'])->name('export');
            Route::post('/import', [MemoryController::class, 'import'])->name('import');
            Route::get('/stats', [MemoryController::class, 'stats'])->name('stats');
        });

        // Vector Memory Management routes
        Route::prefix('vector-memory')->name('vector-memory.')->group(function () {
            Route::get('/', [VectorMemoryController::class, 'index'])->name('index');
            Route::post('/', [VectorMemoryController::class, 'store'])->name('store');
            Route::put('/{memoryId}/importance', [VectorMemoryController::class, 'updateImportance'])->name('update-importance');
            Route::delete('/{memoryId}', [VectorMemoryController::class, 'destroy'])->name('destroy');
            Route::post('/clear', [VectorMemoryController::class, 'clear'])->name('clear');
            Route::post('/search', [VectorMemoryController::class, 'search'])->name('search');
            Route::get('/export', [VectorMemoryController::class, 'export'])->name('export');
            Route::post('/import', [VectorMemoryController::class, 'import'])->name('import');
            Route::get('/stats', [VectorMemoryController::class, 'stats'])->name('stats');
        });

    });

});

<?php

use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\AgentTaskController;
use App\Http\Controllers\Admin\EngineController;
use App\Http\Controllers\Admin\GoalController;
use App\Http\Controllers\Admin\JournalController;
use App\Http\Controllers\Admin\KnownSourceController;
use App\Http\Controllers\Admin\MemoryController;
use App\Http\Controllers\Admin\PersonController;
use App\Http\Controllers\Admin\PluginController;
use App\Http\Controllers\Admin\PresetCapabilityController;
use App\Http\Controllers\Admin\PresetController;
use App\Http\Controllers\Admin\PresetMcpController;
use App\Http\Controllers\Admin\PresetPromptController;
use App\Http\Controllers\Admin\PresetSandboxController;
use App\Http\Controllers\Admin\SandboxController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SkillController;
use App\Http\Controllers\Admin\TelegramController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VectorMemoryController;
use App\Http\Controllers\Admin\WorkspaceController;
use App\Http\Controllers\ApiKeyController;
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
        Route::get('latest-messages', [ChatController::class, 'getLatestMessages'])->name('latest-messages');
        Route::get('older-messages', [ChatController::class, 'loadOlderMessages'])->name('older-messages');
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

        Route::prefix('/api-keys')->name('api-keys.')->group(function () {
            Route::get('/', [ApiKeyController::class, 'index'])  ->name('index');
            Route::post('/', [ApiKeyController::class, 'store'])  ->name('store');
            Route::delete('/{id}', [ApiKeyController::class, 'destroy'])->name('destroy');
        });

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

            if (config('sandbox.enabled', false)) {
                Route::prefix('{presetId}/sandbox')->name('sandbox.')->group(function () {
                    Route::get('/', [PresetSandboxController::class, 'getAssignedSandbox'])->name('get');
                    Route::post('/assign', [PresetSandboxController::class, 'assignSandbox'])->name('assign');
                    Route::post('/create', [PresetSandboxController::class, 'createAndAssignSandbox'])->name('create');
                    Route::delete('/', [PresetSandboxController::class, 'unassignSandbox'])->name('unassign');
                    Route::get('/check', [PresetSandboxController::class, 'hasAssignedSandbox'])->name('check');
                });
            }

            // MCP management routes
            Route::prefix('/{presetId}/mcp')->name('mcp.')->group(function () {
                Route::get('/', [PresetMcpController::class, 'index'])->name('index');
                Route::post('/', [PresetMcpController::class, 'store'])->name('store');
                Route::delete('/{serverId}', [PresetMcpController::class, 'destroy'])->name('destroy');
                Route::patch('/{serverId}/toggle', [PresetMcpController::class, 'toggle'])->name('toggle');
                Route::post('/{serverId}/ping', [PresetMcpController::class, 'ping'])->name('ping');
            });

            // Telegram integration routes
            Route::prefix('/{presetId}/telegram')->name('telegram.')->group(function () {
                Route::get('/status', [TelegramController::class, 'status'])->name('status');
                Route::post('/auth/init', [TelegramController::class, 'authInit'])->name('auth.init');
                Route::post('/auth/phone', [TelegramController::class, 'authPhone'])->name('auth.phone');
                Route::post('/auth/code', [TelegramController::class, 'authCode'])->name('auth.code');
                Route::post('/auth/password', [TelegramController::class, 'authPassword'])->name('auth.password');
                Route::delete('/session', [TelegramController::class, 'destroySession'])->name('session.destroy');
            });

            // Preset Prompts
            Route::prefix('/{id}/prompts')->name('prompts.')->group(function () {
                Route::get('/', [PresetPromptController::class, 'index'])->name('index');
                Route::post('/', [PresetPromptController::class, 'store'])->name('store');
                Route::put('/{promptId}', [PresetPromptController::class, 'update'])->name('update');
                Route::delete('/{promptId}', [PresetPromptController::class, 'destroy'])->name('destroy');
                Route::patch('/{promptId}/activate', [PresetPromptController::class, 'activate'])->name('activate');
                Route::post('/{promptId}/duplicate', [PresetPromptController::class, 'duplicate'])->name('duplicate');
            });
        });

        // AI Engines management routes
        Route::prefix('engines')->name('engines.')->group(function () {
            Route::get('/', [EngineController::class, 'index'])->name('index');
            Route::get('/{engineName}/models', [EngineController::class, 'getAvailableModels'])->name('models');
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
            Route::get('/{presetId?}', [PluginController::class, 'index'])->name('index')->where('presetId', '[0-9]+');
            Route::get('/health/{presetId?}', [PluginController::class, 'health'])->name('health')->where('presetId', '[0-9]+');
            Route::post('/health-check/{presetId?}', [PluginController::class, 'healthCheck'])->name('health-check')->where('presetId', '[0-9]+');
            Route::post('/copy-configurations', [PluginController::class, 'copyConfigurations'])->name('copy-configurations');

            Route::prefix('{pluginName}')->group(function () {
                Route::get('/{presetId?}', [PluginController::class, 'show'])->name('show')->where('presetId', '[0-9]+');
                Route::post('/toggle/{presetId?}', [PluginController::class, 'toggle'])->name('toggle')->where('presetId', '[0-9]+');
                Route::post('/test/{presetId?}', [PluginController::class, 'test'])->name('test')->where('presetId', '[0-9]+');
                Route::post('/update/{presetId?}', [PluginController::class, 'update'])->name('update')->where('presetId', '[0-9]+');
                Route::post('/reset/{presetId?}', [PluginController::class, 'reset'])->name('reset')->where('presetId', '[0-9]+');
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

        // Capabilities Management routes
        Route::prefix('capabilities')->name('capabilities.')->group(function () {
            // Index page — with optional preset selector (same pattern as plugins)
            Route::get('/{presetId?}', [PresetCapabilityController::class, 'index'])->name('index');
            // Reload capabilities JSON after preset switch
            Route::get('/{presetId}/data', [PresetCapabilityController::class, 'show'])->name('show');
            // Save config for a specific capability type
            Route::put('/{presetId}/{capability}', [PresetCapabilityController::class, 'update'])->name('update');
            // Test the current config
            Route::post('/{presetId}/{capability}/test', [PresetCapabilityController::class, 'test'])->name('test');
        });

        // Skills Management routes
        Route::prefix('skills')->name('skills.')->group(function () {
            Route::get('/', [SkillController::class, 'index'])->name('index');
            Route::post('/', [SkillController::class, 'store'])->name('store');
            Route::get('/{number}', [SkillController::class, 'show'])->name('show');
            Route::post('/add-item', [SkillController::class, 'addItem'])->name('add-item');
            Route::post('/update-item', [SkillController::class, 'updateItem'])->name('update-item');
            Route::delete('/item', [SkillController::class, 'destroyItem'])->name('destroy-item');
            Route::delete('/{number}', [SkillController::class, 'destroy'])->name('destroy');
        });

        // Known Sources Management routes
        Route::prefix('known-sources')->name('known-sources.')->group(function () {
            Route::get('/', [KnownSourceController::class, 'index'])->name('index');
            Route::post('/', [KnownSourceController::class, 'store'])->name('store');
            Route::post('/reorder', [KnownSourceController::class, 'reorder'])->name('reorder');
            Route::post('/pool', [KnownSourceController::class, 'poolStore'])->name('pool.store');
            Route::post('/pool/clear', [KnownSourceController::class, 'poolClear'])->name('pool.clear');
            Route::delete('/pool/{id}', [KnownSourceController::class, 'poolDestroy'])->name('pool.destroy');
            Route::put('/{id}', [KnownSourceController::class, 'update'])->name('update');
            Route::delete('/{id}', [KnownSourceController::class, 'destroy'])->name('destroy');
        });

        // Workspace Management routes
        Route::prefix('workspace')->name('workspace.')->group(function () {
            Route::get('/', [WorkspaceController::class, 'index'])  ->name('index');
            Route::post('/', [WorkspaceController::class, 'store'])  ->name('store');
            Route::put('/{key}', [WorkspaceController::class, 'update']) ->name('update');
            Route::delete('/{key}', [WorkspaceController::class, 'destroy'])->name('destroy');
            Route::post('/clear', [WorkspaceController::class, 'clear'])  ->name('clear');
        });

        // Goal Management routes
        Route::prefix('goals')->name('goals.')->group(function () {
            Route::get('/', [GoalController::class, 'index'])       ->name('index');
            Route::post('/', [GoalController::class, 'store'])       ->name('store');
            Route::get('/{number}', [GoalController::class, 'show'])        ->name('show');
            Route::post('/progress', [GoalController::class, 'storeProgress'])->name('progress');
            Route::patch('/{number}/status', [GoalController::class, 'setStatus'])   ->name('set-status');
            Route::delete('/{number}', [GoalController::class, 'destroy'])      ->name('destroy');
            Route::post('/clear', [GoalController::class, 'clear'])        ->name('clear');
        });

        // Agent Management routes
        Route::prefix('agents')->name('agents.')->group(function () {
            Route::get('/', [AgentController::class, 'index'])->name('index');
            Route::post('/', [AgentController::class, 'store'])->name('store');
            Route::get('/{id}', [AgentController::class, 'show'])->name('show');
            Route::put('/{id}', [AgentController::class, 'update'])->name('update');
            Route::delete('/{id}', [AgentController::class, 'destroy'])->name('destroy');
            Route::post('/{id}/clear', [AgentController::class, 'clear'])->name('agents.clear');

            // Roles
            Route::post('/{id}/roles', [AgentController::class, 'storeRole'])->name('roles.store');
            Route::put('/{id}/roles/{roleId}', [AgentController::class, 'updateRole'])->name('roles.update');
            Route::delete('/{id}/roles/{roleId}', [AgentController::class, 'destroyRole'])->name('roles.destroy');
        });

        // Agent Tasks (observation + manual control)
        Route::prefix('agent-tasks')->name('agent-tasks.')->group(function () {
            Route::get('/', [AgentTaskController::class, 'index'])->name('index');
            Route::post('/', [AgentTaskController::class, 'store'])->name('store');
            Route::patch('/{taskId}/status', [AgentTaskController::class, 'setStatus'])->name('set-status');
            Route::delete('/{taskId}', [AgentTaskController::class, 'destroy'])->name('destroy');
            Route::post('/clear', [AgentTaskController::class, 'clear'])->name('clear');
        });

        // Person Memory Management routes
        Route::prefix('person-memory')->name('person-memory.')->group(function () {
            Route::get('/', [PersonController::class, 'index'])       ->name('index');
            Route::post('/fact', [PersonController::class, 'addFact'])     ->name('add-fact');
            Route::delete('/fact', [PersonController::class, 'deleteFact'])  ->name('delete-fact');
            Route::post('/forget', [PersonController::class, 'forgetPerson'])->name('forget');
            Route::post('/alias/add', [PersonController::class, 'addAlias'])    ->name('add-alias');
            Route::post('/alias/remove', [PersonController::class, 'removeAlias']) ->name('remove-alias');
            Route::post('/clear', [PersonController::class, 'clearAll'])    ->name('clear');
        });

        // Journal Management routes
        Route::prefix('journal')->name('journal.')->group(function () {
            Route::get('/', [JournalController::class, 'index'])->name('index');
            Route::post('/', [JournalController::class, 'store'])->name('store');
            Route::delete('/{entryId}', [JournalController::class, 'destroy'])->name('destroy');
            Route::post('/clear', [JournalController::class, 'clear'])->name('clear');
            Route::post('/search', [JournalController::class, 'search'])->name('search');
        });

        if (config('sandbox.enabled', false)) {
            // Sandbox Management routes
            Route::prefix('sandboxes')->name('sandboxes.')->group(function () {
                Route::get('/', [SandboxController::class, 'index'])->name('index');
                Route::get('/list', [SandboxController::class, 'list'])->name('list');
                Route::get('/config', [SandboxController::class, 'getConfig'])->name('config');
                Route::post('/', [SandboxController::class, 'store'])->name('store');
                Route::get('/{sandboxId}', [SandboxController::class, 'show'])->name('show');
                Route::post('/{sandboxId}/start', [SandboxController::class, 'start'])->name('start');
                Route::post('/{sandboxId}/stop', [SandboxController::class, 'stop'])->name('stop');
                Route::delete('/{sandboxId}', [SandboxController::class, 'destroy'])->name('destroy');
                Route::post('/{sandboxId}/reset', [SandboxController::class, 'reset'])->name('reset');
                Route::post('/{sandboxId}/execute-command', [SandboxController::class, 'executeCommand'])->name('execute-command');
                Route::post('/{sandboxId}/execute-code', [SandboxController::class, 'executeCode'])->name('execute-code');
                Route::post('/{sandboxId}/install-packages', [SandboxController::class, 'installPackages'])->name('install-packages');
                Route::post('/cleanup', [SandboxController::class, 'cleanup'])->name('cleanup');
                Route::get('/operation/{operationId}/status', [SandboxController::class, 'getOperationStatus'])->name('operation-status');
                Route::get('/supported-options', [SandboxController::class, 'getSupportedOptions'])->name('supported-options');
                Route::get('/operations/recent', [SandboxController::class, 'getRecentOperations'])->name('operations.recent');
                Route::post('/operations/clear', [SandboxController::class, 'clearOperations'])->name('operations.clear');
                Route::get('/operation/{operationId}/status', [SandboxController::class, 'getOperationStatus'])->name('operation-status');

                // Relations to presets
                Route::get('/{sandboxId}/presets', [PresetSandboxController::class, 'getPresetsForSandbox'])->name('presets');
                Route::delete('/{sandboxId}/cleanup-assignments', [PresetSandboxController::class, 'cleanupSandboxAssignments'])->name('cleanup-assignments');
                Route::post('/validate-assignments', [PresetSandboxController::class, 'validateAndCleanupAll'])->name('validate-assignments');
                Route::get('/assignment-stats', [PresetSandboxController::class, 'getStats'])->name('assignment-stats');

            });
        }
    });

});

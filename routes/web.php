<?php

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\AdminMiddleware;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Mainpage route
Route::get('/', function () {
    return Inertia::render('Welcome/Index');
})->name('home');

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
        Route::post('model-settings', [ChatController::class, 'updateModelSettings'])->middleware(AdminMiddleware::class)->name('model-settings');
        Route::post('export', [ChatController::class, 'exportChat'])->middleware(AdminMiddleware::class)->name('export');
    });

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
    });

});

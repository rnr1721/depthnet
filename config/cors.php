<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => [
        // Mainpage
        '',

        // Authentication
        'login',
        'logout',
        'register',
        'forgot-password',
        'reset-password/*',

        // Chat
        'chat',
        'chat/*',

        // Profile
        'profile',
        'profile/*',

        // Admin
        'admin/*',

        // Sanctum
        'sanctum/csrf-cookie',

        // API
        'api/*',
    ],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'],

    'allowed_origins' => [
        env('APP_URL'),
    ],

    'allowed_origins_patterns' => [
        '/^http:\/\/localhost:\d+$/',
        '/^http:\/\/127\.0\.0\.1:\d+$/',
    ],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Content-Length',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-Inertia',
        'X-Inertia-Version',
    ],

    'exposed_headers' => [
        'X-Inertia',
        'X-Inertia-Location',
        'X-Inertia-Version',
        'X-CSRF-TOKEN',
    ],

    'max_age' => 0,

    'supports_credentials' => true,

];

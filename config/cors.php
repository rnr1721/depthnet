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
        // Main page
        '',

        // Authentication routes
        'login',
        'logout',
        'register',
        'forgot-password',
        'reset-password/*',
        'email',
        'email/*',

        // Chat functionality
        'chat',
        'chat/*',

        // Profile management
        'profile',
        'profile/*',

        // Admin panel
        'admin/*',

        // Laravel Sanctum
        'sanctum/csrf-cookie',

        // API routes
        'api/*',

        // Vite HMR and development assets (only in development)
        ...(env('APP_ENV') === 'local' ? [
            '@vite/*',
            'build/*',
            'resources/*',
        ] : []),
    ],

    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
        'HEAD',
    ],

    /**
     * Configure allowed origins based on environment
     */
    'allowed_origins' => array_filter([
        env('APP_URL'),

        // Vite development server (only in development)
        ...(env('APP_ENV') === 'local' ? [
            env('VITE_HMR_HOST') ? 'http://' . env('VITE_HMR_HOST') . ':' . env('VITE_HMR_PORT') : null,
            env('VITE_HMR_HOST') ? 'ws://' . env('VITE_HMR_HOST') . ':' . env('VITE_HMR_PORT') : null,
        ] : []),
    ]),

    /**
     * Patterns for dynamic origin validation
     */
    'allowed_origins_patterns' => array_filter([
        // Local development patterns
        '/^http:\/\/localhost:\d+$/',
        '/^http:\/\/127\.0\.0\.1:\d+$/',
        '/^ws:\/\/localhost:\d+$/',
        '/^ws:\/\/127\.0\.0\.1:\d+$/',

        // Docker internal network patterns (only in development)
        ...(env('APP_ENV') === 'local' ? [
            '/^http:\/\/\d+\.\d+\.\d+\.\d+:\d+$/',  // Any IP with port
            '/^ws:\/\/\d+\.\d+\.\d+\.\d+:\d+$/',    // WebSocket on any IP
        ] : []),

        // Custom patterns from environment
        env('CORS_ALLOWED_PATTERNS') ? env('CORS_ALLOWED_PATTERNS') : null,
    ]),

    'allowed_headers' => [
        'Accept',
        'Accept-Language',
        'Authorization',
        'Content-Type',
        'Content-Length',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-Inertia',
        'X-Inertia-Version',
        'X-Inertia-Partial-Component',
        'X-Inertia-Partial-Data',
        'X-Socket-Id',
        'Cache-Control',
        'Pragma',
        // Development headers (only in development)
        ...(env('APP_ENV') === 'local' ? [
            'X-Vite-HMR',
            'X-Debug-Token',
        ] : []),
    ],

    'exposed_headers' => [
        'X-Inertia',
        'X-Inertia-Location',
        'X-Inertia-Version',
        'X-CSRF-TOKEN',
        'X-App-Version',
        'X-Request-ID',
        'Location',

        // Rate limiting headers
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',

        // Development headers (only in development)
        ...(env('APP_ENV') === 'local' ? [
            'X-Debug-Token',
            'X-Debug-Token-Link',
        ] : []),
    ],

    /**
     * Maximum age for preflight cache
     * Set to reasonable value for production
     */
    'max_age' => env('CORS_MAX_AGE', env('APP_ENV') === 'production' ? 3600 : 0),

    /**
     * Support credentials (cookies, authorization headers)
     * Required for Sanctum and session-based authentication
     */
    'supports_credentials' => true,
];

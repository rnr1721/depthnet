<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Engines Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for different AI model engines. Each engine can be
    | individually enabled/disabled and configured with default settings.
    | These settings can be overridden in individual presets.
    |
    */

    'engines' => [

        /*
        |--------------------------------------------------------------------------
        | Mock Engine (for testing)
        |--------------------------------------------------------------------------
        */
        'mock' => [
            'enabled' => env('AI_MOCK_ENABLED', true),
            'is_default' => env('AI_MOCK_DEFAULT', true),
            'display_name' => 'Mock Engine',
            'description' => 'Test engine for development and debugging',

            // Default config values
            'processing_delay' => env('AI_MOCK_DELAY', 2),
            'response_language' => env('AI_MOCK_LANGUAGE', 'ru'),
            'scenario_mode' => env('AI_MOCK_SCENARIO_MODE', 'random'),
            'enable_user_interaction' => env('AI_MOCK_USER_INTERACTION', true),
            'enable_command_simulation' => env('AI_MOCK_COMMAND_SIMULATION', true),
            'enable_dopamine_response' => env('AI_MOCK_DOPAMINE_RESPONSE', true),
            'max_response_length' => env('AI_MOCK_MAX_LENGTH', 1000),
            'system_prompt' => 'You are a useful AI assistant.',
        ],

        /*
        |--------------------------------------------------------------------------
        | OpenAI Configuration
        |--------------------------------------------------------------------------
        */
        'openai' => [
            'enabled' => env('AI_OPENAI_ENABLED', true),
            'is_default' => env('AI_OPENAI_DEFAULT', false),
            'display_name' => 'OpenAI',
            'description' => 'GPT-4, GPT-3.5 and other OpenAI models',

            // API settings
            'api_key' => env('OPENAI_API_KEY'),
            'server_url' => env('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions'),

            // Default config values
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
            'max_tokens' => env('OPENAI_MAX_TOKENS', 4096),
            'temperature' => env('OPENAI_TEMPERATURE', 0.7),
            'top_p' => env('OPENAI_TOP_P', 0.8),
            'frequency_penalty' => env('OPENAI_FREQUENCY_PENALTY', 0.0),
            'presence_penalty' => env('OPENAI_PRESENCE_PENALTY', 0.0),
            'system_prompt' => 'You are a useful AI assistant.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Claude Configuration
        |--------------------------------------------------------------------------
        */
        'claude' => [
            'enabled' => env('AI_CLAUDE_ENABLED', false),
            'is_default' => env('AI_CLAUDE_DEFAULT', false),
            'display_name' => 'Claude (Anthropic)',
            'description' => 'Claude 3.5 Sonnet, Opus, Haiku by Anthropic',

            // API settings
            'api_key' => env('CLAUDE_API_KEY'),
            'server_url' => env('CLAUDE_API_URL', 'https://api.anthropic.com/v1/messages'),

            // Default config values
            'model' => env('CLAUDE_MODEL', 'claude-3-5-sonnet-20241022'),
            'max_tokens' => env('CLAUDE_MAX_TOKENS', 4096),
            'temperature' => env('CLAUDE_TEMPERATURE', 0.5),
            'top_p' => env('CLAUDE_TOP_P', 0.7),
            'system_prompt' => 'You are a useful AI assistant.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Local Models Configuration (Ollama, LM Studio, etc.)
        |--------------------------------------------------------------------------
        */
        'local' => [
            'enabled' => env('AI_LOCAL_ENABLED', false),
            'is_default' => env('AI_LOCAL_DEFAULT', false),
            'display_name' => 'Local models',
            'description' => 'Local models via Ollama, LM Studio and other servers',

            // Server settings
            'server_url' => env('AI_LOCAL_SERVER_URL', 'http://localhost:11434'),
            'server_type' => env('AI_LOCAL_SERVER_TYPE', 'ollama'), // ollama, lmstudio, textgen, koboldcpp

            // Default config values
            'model' => env('AI_LOCAL_MODEL', 'llama3'),
            'model_family' => env('AI_LOCAL_MODEL_FAMILY', 'llama'), // llama, phi, mistral, gemma
            'temperature' => env('AI_LOCAL_TEMPERATURE', 0.7),
            'max_tokens' => env('AI_LOCAL_MAX_TOKENS', 2048),
            'top_p' => env('AI_LOCAL_TOP_P', 0.9),
            'top_k' => env('AI_LOCAL_TOP_K', 40),
            'repeat_penalty' => env('AI_LOCAL_REPEAT_PENALTY', 1.1),
            'timeout' => env('AI_LOCAL_TIMEOUT', 60),
            'cleanup_enabled' => env('AI_LOCAL_CLEANUP', true),
            'system_prompt' => 'You are a useful AI assistant.',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Default Presets Configuration
    |--------------------------------------------------------------------------
    |
    | These presets will be automatically created during migration if they
    | don't exist. You can customize them or add your own.
    |
    */
    'default_presets' => [
        [
            'name' => 'Mock',
            'description' => 'Mock engine for development and testing',
            'engine_name' => 'mock',
            'system_prompt' => 'You are useful AI assistant',
            'engine_config' => [
                'processing_delay' => 1,
                'scenario_mode' => 'random',
                'response_language' => 'en',
            ],
            'is_active' => true,
            'is_default' => true,
        ]
    ]

];

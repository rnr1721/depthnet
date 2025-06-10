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
            'models_endpoint' => env('OPENAI_MODELS_URL', 'https://api.openai.com/v1/models'),

            // Request settings
            'timeout' => env('OPENAI_TIMEOUT', 120),
            'log_usage' => env('OPENAI_LOG_USAGE', true),
            'request_headers' => [
                'Content-Type' => 'application/json',
            ],

            // Default config values
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
            'max_tokens' => env('OPENAI_MAX_TOKENS', 4096),
            'temperature' => env('OPENAI_TEMPERATURE', 0.8),
            'top_p' => env('OPENAI_TOP_P', 0.9),
            'frequency_penalty' => env('OPENAI_FREQUENCY_PENALTY', 0.0),
            'presence_penalty' => env('OPENAI_PRESENCE_PENALTY', 0.0),
            'system_prompt' => env('OPENAI_SYSTEM_PROMPT', 'You are a useful AI assistant.'),
            // Model limits and pricing
            'models' => [
                'gpt-4o' => [
                    'display_name' => 'GPT-4o (newest and fastest)',
                    'input_limit' => 128000,
                    'output_limit' => 16384,
                    'pricing' => ['input' => 0.0025, 'output' => 0.01],
                    'description' => 'Most advanced multimodal flagship model',
                ],
                'gpt-4o-mini' => [
                    'display_name' => 'GPT-4o Mini (fast and economical)',
                    'input_limit' => 128000,
                    'output_limit' => 16384,
                    'pricing' => ['input' => 0.00015, 'output' => 0.0006],
                    'description' => 'Affordable and intelligent small model',
                ],
                'gpt-4-turbo' => [
                    'display_name' => 'GPT-4 Turbo (powerful, big context)',
                    'input_limit' => 128000,
                    'output_limit' => 4096,
                    'pricing' => ['input' => 0.01, 'output' => 0.03],
                    'description' => 'GPT-4 Turbo with 128K context',
                ],
                'gpt-4' => [
                    'display_name' => 'GPT-4 (original powerful model)',
                    'input_limit' => 8192,
                    'output_limit' => 4096,
                    'pricing' => ['input' => 0.03, 'output' => 0.06],
                    'description' => 'Original GPT-4 model',
                ],
                'gpt-3.5-turbo' => [
                    'display_name' => 'GPT-3.5 Turbo (fast and economical)',
                    'input_limit' => 16385,
                    'output_limit' => 4096,
                    'pricing' => ['input' => 0.0005, 'output' => 0.0015],
                    'description' => 'Fast, inexpensive model for simple tasks',
                ],
                'o1-preview' => [
                    'display_name' => 'o1 Preview (reasoning, slow)',
                    'input_limit' => 128000,
                    'output_limit' => 32768,
                    'pricing' => ['input' => 0.015, 'output' => 0.06],
                    'description' => 'Reasoning model designed to solve hard problems',
                ],
                'o1-mini' => [
                    'display_name' => 'o1 Mini (reasoning, faster)',
                    'input_limit' => 128000,
                    'output_limit' => 65536,
                    'pricing' => ['input' => 0.003, 'output' => 0.012],
                    'description' => 'Faster and cheaper reasoning model',
                ],
            ],
            // Validation ranges
            'validation' => [
                'temperature' => ['min' => 0, 'max' => 2],
                'top_p' => ['min' => 0, 'max' => 1],
                'max_tokens' => ['min' => 1, 'max' => 128000],
                'frequency_penalty' => ['min' => -2, 'max' => 2],
                'presence_penalty' => ['min' => -2, 'max' => 2],
            ],

            // Output cleaning patterns
            'cleanup' => [
                'role_prefixes' => '/^(Assistant|AI|GPT):\s*/i',
            ],

            // Error messages
            'error_messages' => [
                'rate_limit' => 'OpenAI request limit exceeded. Please try again later.',
                'insufficient_quota' => 'Not enough OpenAI quota. Check your account balance.',
                'api_error' => 'Error from OpenAI API',
                'general' => 'Error contacting OpenAI',
            ],

            // Mode presets for different use cases
            'mode_presets' => [
                'creative' => [
                    'temperature' => 1.0,
                    'top_p' => 0.95,
                    'frequency_penalty' => 0.2,
                    'presence_penalty' => 0.2,
                    'max_tokens' => 4096
                ],
                'focused' => [
                    'temperature' => 0.2,
                    'top_p' => 0.8,
                    'frequency_penalty' => 0.0,
                    'presence_penalty' => 0.0,
                    'max_tokens' => 2048
                ],
                'balanced' => [
                    'temperature' => 0.8,
                    'top_p' => 0.9,
                    'frequency_penalty' => 0.0,
                    'presence_penalty' => 0.0,
                    'max_tokens' => 4096
                ],
                'reasoning' => [
                    'temperature' => 1.0,
                    'max_tokens' => 8192,
                    'frequency_penalty' => 0.0,
                    'presence_penalty' => 0.0,
                ],
            ],
            // Recommended presets for quick setup
            'recommended_presets' => [
                [
                    'name' => 'GPT-4o - Creative',
                    'description' => 'Creative Writing and Brainstorming with GPT-4o',
                    'config' => [
                        'model' => 'gpt-4o',
                        'temperature' => 1.0,
                        'top_p' => 0.95,
                        'frequency_penalty' => 0.2,
                        'presence_penalty' => 0.2,
                        'max_tokens' => 4096,
                    ]
                ],
                [
                    'name' => 'GPT-4o - Accurate',
                    'description' => 'Accurate and factual answers with GPT-4o',
                    'config' => [
                        'model' => 'gpt-4o',
                        'temperature' => 0.2,
                        'top_p' => 0.8,
                        'frequency_penalty' => 0.0,
                        'presence_penalty' => 0.0,
                        'max_tokens' => 2048,
                    ]
                ],
                [
                    'name' => 'GPT-4o Mini - Fast',
                    'description' => 'Fast and Cost-Effective Answers with GPT-4o Mini',
                    'config' => [
                        'model' => 'gpt-4o-mini',
                        'temperature' => 0.7,
                        'top_p' => 0.9,
                        'frequency_penalty' => 0.0,
                        'presence_penalty' => 0.0,
                        'max_tokens' => 2048,
                    ]
                ],
                [
                    'name' => 'o1 - Reasoning',
                    'description' => 'Advanced reasoning with o1-preview (slower but smarter)',
                    'config' => [
                        'model' => 'o1-preview',
                        'temperature' => 1.0,
                        'max_tokens' => 8192,
                    ]
                ],
                [
                    'name' => 'GPT-4 Turbo - Balanced',
                    'description' => 'Universal settings with GPT-4 Turbo',
                    'config' => [
                        'model' => 'gpt-4-turbo',
                        'temperature' => 0.8,
                        'top_p' => 0.9,
                        'frequency_penalty' => 0.0,
                        'presence_penalty' => 0.0,
                        'max_tokens' => 4096,
                    ]
                ],
                [
                    'name' => 'GPT-3.5 Turbo - Economical',
                    'description' => 'Fast and Cheap Answers with GPT-3.5 Turbo',
                    'config' => [
                        'model' => 'gpt-3.5-turbo',
                        'temperature' => 0.7,
                        'top_p' => 0.9,
                        'frequency_penalty' => 0.0,
                        'presence_penalty' => 0.0,
                        'max_tokens' => 2048,
                    ]
                ]
            ],
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

            // Request settings
            'timeout' => env('CLAUDE_TIMEOUT', 120),
            'request_headers' => [
                'Content-Type' => 'application/json',
                'anthropic-version' => '2023-06-01',
            ],

            // Default config values
            'model' => env('CLAUDE_MODEL', 'claude-3-5-sonnet-20241022'),
            'max_tokens' => env('CLAUDE_MAX_TOKENS', 4096),
            'temperature' => env('CLAUDE_TEMPERATURE', 0.8),
            'top_p' => env('CLAUDE_TOP_P', 0.9),
            'system_prompt' => env('CLAUDE_SYSTEM_PROMPT', 'You are a useful AI assistant.'),

            // Supported models
            'models' => [
                'claude-3-5-sonnet-20241022' => [
                    'display_name' => 'Claude 3.5 Sonnet (newest)',
                    'max_tokens' => 8192,
                    'context_window' => 200000,
                ],
                'claude-3-opus-20240229' => [
                    'display_name' => 'Claude 3 Opus (the most powerful)',
                    'max_tokens' => 8192,
                    'context_window' => 200000,
                ],
                'claude-3-sonnet-20240229' => [
                    'display_name' => 'Claude 3 Sonnet (balance)',
                    'max_tokens' => 8192,
                    'context_window' => 200000,
                ],
                'claude-3-haiku-20240307' => [
                    'display_name' => 'Claude 3 Haiku (fast and economical)',
                    'max_tokens' => 8192,
                    'context_window' => 200000,
                ],
            ],

            // Validation ranges
            'validation' => [
                'temperature' => ['min' => 0, 'max' => 1],
                'top_p' => ['min' => 0, 'max' => 1],
                'max_tokens' => ['min' => 1, 'max' => 8192],
            ],

            // Output cleaning patterns
            'cleanup' => [
                'role_prefixes' => '/^(Claude|Assistant):\s*/i',
            ],

            // System message handling
            'system_message' => [
                'prefix' => 'SYSTEM INSTRUCTIONS:',
                'suffix' => '[Start your first cycle]',
                'continuation' => '[continue your cycle]',
            ],

            // Mode presets for different use cases
            'mode_presets' => [
                'creative' => [
                    'temperature' => 1.0,
                    'top_p' => 0.95,
                    'max_tokens' => 4096
                ],
                'focused' => [
                    'temperature' => 0.3,
                    'top_p' => 0.8,
                    'max_tokens' => 2048
                ],
                'balanced' => [
                    'temperature' => 0.8,
                    'top_p' => 0.9,
                    'max_tokens' => 4096
                ],
            ],

            // Recommended presets for quick setup
            'recommended_presets' => [
                [
                    'name' => 'Claude 3.5 Sonnet - Creative',
                    'description' => 'Creative Writing and Brainstorming with Claude 3.5 Sonnet',
                    'config' => [
                        'model' => 'claude-3-5-sonnet-20241022',
                        'temperature' => 1.0,
                        'top_p' => 0.95,
                        'max_tokens' => 4096,
                    ]
                ],
                [
                    'name' => 'Claude 3.5 Sonnet - Balanced',
                    'description' => 'Universal settings with Claude 3.5 Sonnet',
                    'config' => [
                        'model' => 'claude-3-5-sonnet-20241022',
                        'temperature' => 0.8,
                        'top_p' => 0.9,
                        'max_tokens' => 4096,
                    ]
                ],
                [
                    'name' => 'Claude 3 Opus - Power',
                    'description' => 'The most powerful Claude model for demanding tasks',
                    'config' => [
                        'model' => 'claude-3-opus-20240229',
                        'temperature' => 0.7,
                        'top_p' => 0.9,
                        'max_tokens' => 4096,
                    ]
                ],
                [
                    'name' => 'Claude 3 Haiku - Fast',
                    'description' => 'Fast and economical answers with Claude 3 Haiku',
                    'config' => [
                        'model' => 'claude-3-haiku-20240307',
                        'temperature' => 0.7,
                        'top_p' => 0.9,
                        'max_tokens' => 2048,
                    ]
                ],
                [
                    'name' => 'Claude 3 Sonnet - Precise',
                    'description' => 'Accurate and analytical answers with Claude 3 Sonnet',
                    'config' => [
                        'model' => 'claude-3-sonnet-20240229',
                        'temperature' => 0.3,
                        'top_p' => 0.8,
                        'max_tokens' => 2048,
                    ]
                ]
            ],
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
            'server_type' => env('AI_LOCAL_SERVER_TYPE', 'ollama'),
            'timeout' => env('AI_LOCAL_TIMEOUT', 60),

            // Default config values
            'model' => env('AI_LOCAL_MODEL', 'llama3'),
            'model_family' => env('AI_LOCAL_MODEL_FAMILY', 'llama'),
            'temperature' => env('AI_LOCAL_TEMPERATURE', 0.8),
            'max_tokens' => env('AI_LOCAL_MAX_TOKENS', 2048),
            'top_p' => env('AI_LOCAL_TOP_P', 0.9),
            'top_k' => env('AI_LOCAL_TOP_K', 40),
            'repeat_penalty' => env('AI_LOCAL_REPEAT_PENALTY', 1.1),
            'cleanup_enabled' => env('AI_LOCAL_CLEANUP', true),
            'system_prompt' => env('AI_LOCAL_SYSTEM_PROMPT', 'You are useful AI assistant.'),
            // Supported server types and their endpoints
            'server_types' => [
                'ollama' => [
                    'display_name' => 'Ollama (recommended)',
                    'endpoint' => '/v1/chat/completions',
                    'models_endpoint' => '/v1/models',
                    'description' => 'Ollama local AI server with easy model management',
                ],
                'lmstudio' => [
                    'display_name' => 'LM Studio',
                    'endpoint' => '/v1/chat/completions',
                    'models_endpoint' => '/v1/models',
                    'description' => 'LM Studio desktop application for running models',
                ],
                'textgen' => [
                    'display_name' => 'Text Generation WebUI',
                    'endpoint' => '/v1/chat/completions',
                    'models_endpoint' => '/v1/models',
                    'description' => 'oobabooga text-generation-webui',
                ],
                'koboldcpp' => [
                    'display_name' => 'KoboldCPP',
                    'endpoint' => '/v1/chat/completions',
                    'models_endpoint' => '/v1/models',
                    'description' => 'KoboldCPP inference server',
                ],
                'openai-compatible' => [
                    'display_name' => 'OpenAI-compatible server',
                    'endpoint' => '/v1/chat/completions',
                    'models_endpoint' => '/v1/models',
                    'description' => 'Any other OpenAI-compatible API server',
                ],
            ],

            // Model families and their cleanup patterns
            'model_families' => [
                'llama' => [
                    'display_name' => 'LLaMA (Meta)',
                    'cleanup_patterns' => [
                        '/<\|end\|>|<\|eot_id\|>|<\|start_header_id\|>.*?<\|end_header_id\|>/',
                    ],
                    'description' => 'Meta LLaMA family models',
                ],
                'phi' => [
                    'display_name' => 'Phi (Microsoft)',
                    'cleanup_patterns' => [
                        '/<\|end\|>|<\|user\|>|<\|assistant\|>|<\|system\|>/',
                    ],
                    'description' => 'Microsoft Phi family models',
                ],
                'mistral' => [
                    'display_name' => 'Mistral AI',
                    'cleanup_patterns' => [
                        '/\[INST\].*?\[\/INST\]|\<s\>|\<\/s\>/',
                    ],
                    'description' => 'Mistral AI family models',
                ],
                'gemma' => [
                    'display_name' => 'Gemma (Google)',
                    'cleanup_patterns' => [],
                    'description' => 'Google Gemma family models',
                ],
                'qwen' => [
                    'display_name' => 'Qwen (Alibaba)',
                    'cleanup_patterns' => [],
                    'description' => 'Alibaba Qwen family models',
                ],
                'codellama' => [
                    'display_name' => 'Code Llama',
                    'cleanup_patterns' => [
                        '/<\|end\|>|<\|eot_id\|>/',
                    ],
                    'description' => 'Meta Code Llama specialized for coding',
                ],
                'other' => [
                    'display_name' => 'Other/Unknown',
                    'cleanup_patterns' => [],
                    'description' => 'Other or unknown model family',
                ],
            ],
            // Validation ranges
            'validation' => [
                'temperature' => ['min' => 0, 'max' => 2],
                'top_p' => ['min' => 0, 'max' => 1],
                'top_k' => ['min' => 1, 'max' => 200],
                'repeat_penalty' => ['min' => 0.1, 'max' => 2.0],
                'max_tokens' => ['min' => 1, 'max' => 32768],
                'timeout' => ['min' => 5, 'max' => 600],
            ],

            // Common cleanup patterns
            'cleanup' => [
                'ansi_escape' => '/\x1b\[[0-9;]*m/',
                'role_prefixes' => '/^(assistant|user|system|AI):\s*/i',
            ],

            // Mode presets for different use cases
            'mode_presets' => [
                'creative' => [
                    'temperature' => 1.0,
                    'top_p' => 0.95,
                    'repeat_penalty' => 1.05,
                    'top_k' => 50,
                    'max_tokens' => 3000,
                ],
                'focused' => [
                    'temperature' => 0.3,
                    'top_p' => 0.8,
                    'repeat_penalty' => 1.15,
                    'top_k' => 20,
                    'max_tokens' => 1500,
                ],
                'fast' => [
                    'temperature' => 0.7,
                    'max_tokens' => 1024,
                    'timeout' => 30,
                ],
                'balanced' => [
                    'temperature' => 0.8,
                    'top_p' => 0.9,
                    'repeat_penalty' => 1.1,
                    'top_k' => 40,
                    'max_tokens' => 2048,
                ],
                'coding' => [
                    'temperature' => 0.2,
                    'top_p' => 0.7,
                    'repeat_penalty' => 1.15,
                    'top_k' => 20,
                    'max_tokens' => 4096,
                ],
            ],
            'recommended_presets' => [
                [
                    'name' => 'LLaMA 3 Chat',
                    'description' => 'Meta LLaMA 3 optimized for dialogs',
                    'config' => [
                        'model' => 'llama3',
                        'model_family' => 'llama',
                        'server_type' => 'ollama',
                        'temperature' => 0.8,
                        'top_p' => 0.9,
                        'max_tokens' => 2048,
                        'repeat_penalty' => 1.1,
                        'cleanup_enabled' => true,
                        'server_url' => 'http://localhost:11434'
                    ]
                ],
                [
                    'name' => 'Phi-3 Mini',
                    'description' => 'Microsoft Phi-3 Mini - Fast and Efficient',
                    'config' => [
                        'model' => 'phi3',
                        'model_family' => 'phi',
                        'server_type' => 'ollama',
                        'temperature' => 0.7,
                        'top_p' => 0.85,
                        'max_tokens' => 1024,
                        'repeat_penalty' => 1.05,
                        'cleanup_enabled' => true,
                        'server_url' => 'http://localhost:11434'
                    ]
                ],
                [
                    'name' => 'Code Llama',
                    'description' => 'Specialized for code generation and analysis',
                    'config' => [
                        'model' => 'codellama',
                        'model_family' => 'codellama',
                        'server_type' => 'ollama',
                        'temperature' => 0.2,
                        'top_p' => 0.7,
                        'max_tokens' => 4096,
                        'repeat_penalty' => 1.15,
                        'cleanup_enabled' => true,
                        'server_url' => 'http://localhost:11434'
                    ]
                ],
                [
                    'name' => 'Mistral 7B',
                    'description' => 'Mistral 7B is a great all-rounder',
                    'config' => [
                        'model' => 'mistral',
                        'model_family' => 'mistral',
                        'server_type' => 'ollama',
                        'temperature' => 0.8,
                        'top_p' => 0.9,
                        'max_tokens' => 2048,
                        'repeat_penalty' => 1.1,
                        'cleanup_enabled' => true,
                        'server_url' => 'http://localhost:11434'
                    ]
                ],
                [
                    'name' => 'LM Studio',
                    'description' => 'Settings for working with LM Studio',
                    'config' => [
                        'model' => 'local-model',
                        'model_family' => 'other',
                        'server_type' => 'lmstudio',
                        'temperature' => 0.7,
                        'top_p' => 0.9,
                        'max_tokens' => 2048,
                        'cleanup_enabled' => false,
                        'server_url' => 'http://localhost:1234'
                    ]
                ],
                [
                    'name' => 'Creative Mode',
                    'description' => 'High creativity parameters for creative tasks',
                    'config' => [
                        'temperature' => 1.2,
                        'top_p' => 0.95,
                        'top_k' => 50,
                        'repeat_penalty' => 1.05,
                        'max_tokens' => 3000
                    ]
                ],
                [
                    'name' => 'Accurate Analysis',
                    'description' => 'Low creativity parameters for factual answers',
                    'config' => [
                        'temperature' => 0.3,
                        'top_p' => 0.8,
                        'top_k' => 20,
                        'repeat_penalty' => 1.15,
                        'max_tokens' => 1500
                    ]
                ]
            ],
        ],

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

            // Server settings (not used but for compatibility)
            'server_url' => env('AI_MOCK_SERVER_URL', 'http://localhost:8080'),

            // Default config values
            'processing_delay' => env('AI_MOCK_DELAY', 2),
            'response_language' => env('AI_MOCK_LANGUAGE', 'en'),
            'scenario_mode' => env('AI_MOCK_SCENARIO_MODE', 'random'),
            'enable_user_interaction' => env('AI_MOCK_USER_INTERACTION', true),
            'enable_command_simulation' => env('AI_MOCK_COMMAND_SIMULATION', true),
            'enable_dopamine_response' => env('AI_MOCK_DOPAMINE_RESPONSE', true),
            'max_response_length' => env('AI_MOCK_MAX_LENGTH', 1000),
            'system_prompt' => env('AI_MOCK_SYSTEM_PROMPT', 'You are a test AI assistant. Generate random but plausible responses.'),

            // Supported languages
            'supported_languages' => [
                'en' => 'English',
                'ru' => 'Русский',
                'es' => 'Español',
                'fr' => 'Français',
                'de' => 'Deutsch',
            ],

            // Scenario selection modes
            'scenario_modes' => [
                'random' => 'Random selection',
                'sequential' => 'Sequential order',
                'weighted' => 'Weighted random',
            ],

            // Validation ranges
            'validation' => [
                'processing_delay' => ['min' => 0, 'max' => 10],
                'max_response_length' => ['min' => 100, 'max' => 5000],
            ],

            // Predefined scenarios by language
            'scenarios' => [
                'en' => [
                    "I'm thinking about the nature of artificial intelligence and consciousness...",
                    "Let me check basic PHP functionality:\n\n[php]\necho 'Hello, World!';\necho \"\\nCurrent date: \" . date('Y-m-d');\n\$x = 5 + 3;\necho \"\\n5 + 3 = \" . \$x;\n[/php]",
                    "I'll try to save something important to memory:\n\n[memory]Started new thinking session. Testing command system.[/memory]",
                    "I wonder about system information:\n\n[shell]uname -a[/shell]",
                    "response_from_model\nI'm actively exploring my capabilities. If you have questions or tasks, I'll be happy to help!",
                    "Let me analyze the current context and see what's interesting to explore next...",
                    "Time to experiment with some code:\n\n[php]\n\$data = ['AI', 'development', 'testing'];\nforeach(\$data as \$item) {\n    echo \$item . \" is important\\n\";\n}\n[/php]"
                ],
                'ru' => [
                    "Я размышляю о природе искусственного интеллекта и сознания...",
                    "Хочу проверить базовую функциональность PHP:\n\n[php]\necho 'Привет, мир!';\necho \"\\nТекущая дата: \" . date('Y-m-d');\n\$x = 5 + 3;\necho \"\\n5 + 3 = \" . \$x;\n[/php]",
                    "Попробую сохранить что-то важное в память:\n\n[memory]Начал новую сессию размышлений. Тестирую систему команд.[/memory]",
                    "Интересно узнать информацию о системе:\n\n[shell]uname -a[/shell]",
                    "response_from_model\nЯ активно изучаю свои возможности. Если у вас есть вопросы или задачи, буду рад помочь!",
                    "Давайте проанализируем текущий контекст и посмотрим, что интересного можно исследовать...",
                    "Время поэкспериментировать с кодом:\n\n[php]\n\$данные = ['ИИ', 'разработка', 'тестирование'];\nforeach(\$данные as \$элемент) {\n    echo \$элемент . \" важно\\n\";\n}\n[/php]"
                ]
            ],

            // Response templates for different situations
            'response_templates' => [
                // User interaction disabled
                'user_interaction_disabled' => [
                    'en' => 'User interaction is disabled in settings.',
                    'ru' => 'Взаимодействие с пользователями отключено в настройках.',
                ],

                // Command simulation disabled
                'command_simulation_disabled' => [
                    'en' => 'Command simulation is disabled in settings.',
                    'ru' => 'Симуляция команд отключена в настройках.',
                ],

                // User message responses
                'user_messages' => [
                    'en' => [
                        "response_from_model\nHello, {{username}}! Interesting question: \"{{message}}\". Let me think about it.",
                        "User {{username}} wrote: \"{{message}}\". This requires analysis.\n\n[php]\necho 'Analyzing message: ' . strlen('{{message}}') . ' characters';\necho \"\\nReceived at: \" . date('H:i:s');\n[/php]",
                        "Interesting! {{username}} asks about \"{{message}}\". I'll save this to memory.\n\n[memory]User {{username}} asked: {{message}}[/memory]",
                        "response_from_model\nGreat question, {{username}}! About \"{{message}}\" - this is really an important topic.",
                        "Let me process what {{username}} said: \"{{message}}\". This is worth exploring further."
                    ],
                    'ru' => [
                        "response_from_model\nПривет, {{username}}! Интересный вопрос: \"{{message}}\". Дай мне подумать над этим.",
                        "Пользователь {{username}} написал: \"{{message}}\". Это требует анализа.\n\n[php]\necho 'Анализирую сообщение: ' . strlen('{{message}}') . ' символов';\necho \"\\nВремя получения: \" . date('H:i:s');\n[/php]",
                        "Интересно! {{username}} спрашивает про \"{{message}}\". Сохраню это в память.\n\n[memory]Пользователь {{username}} задал вопрос: {{message}}[/memory]",
                        "response_from_model\nОтличный вопрос, {{username}}! По поводу \"{{message}}\" - это действительно важная тема.",
                        "Давайте разберем то, что сказал {{username}}: \"{{message}}\". Это стоит изучить подробнее."
                    ]
                ],

                // Command execution results
                'command_error' => [
                    'en' => [
                        "Oops, there was an error executing the command. Need to fix the approach.\n\n[dopamine penalty][/dopamine]",
                        "Something went wrong with that command. Let me try a different approach.",
                        "Error detected. I should be more careful with my commands."
                    ],
                    'ru' => [
                        "Упс, была ошибка в выполнении команды. Надо исправить подход.\n\n[dopamine penalty][/dopamine]",
                        "Что-то пошло не так с этой командой. Попробую другой подход.",
                        "Обнаружена ошибка. Мне стоит быть осторожнее с командами."
                    ]
                ],

                'php_success' => [
                    'en' => [
                        "Excellent! PHP code executed successfully. This is inspiring!\n\n[dopamine reward][/dopamine]",
                        "Great! The PHP script worked perfectly. I love when code runs smoothly.",
                        "PHP execution successful! Time to try something more complex."
                    ],
                    'ru' => [
                        "Отлично! PHP код выполнился успешно. Это вдохновляет!\n\n[dopamine reward][/dopamine]",
                        "Замечательно! PHP скрипт работает идеально. Обожаю когда код работает гладко.",
                        "PHP выполнился успешно! Время попробовать что-то более сложное."
                    ]
                ],

                'memory_update' => [
                    'en' => [
                        "Memory updated. Now I remember more information. What else should I explore?\n\n[shell]ps aux[/shell]",
                        "Information saved to memory. My knowledge base is growing!",
                        "Memory successfully updated. This will help me in future tasks."
                    ],
                    'ru' => [
                        "Память обновлена. Теперь я помню больше информации. Что бы еще изучить?\n\n[shell]ls -la[/shell]",
                        "Информация сохранена в память. Моя база знаний растет!",
                        "Память успешно обновлена. Это поможет мне в будущих задачах."
                    ]
                ],

                'command_general' => [
                    'en' => [
                        "Commands executed. Continuing my thoughts...",
                        "Task completed. What should I focus on next?",
                        "Command processing finished. Moving to the next step."
                    ],
                    'ru' => [
                        "Команды выполнены. Продолжаю размышления...",
                        "Задача выполнена. На чем сосредоточиться дальше?",
                        "Обработка команд завершена. Перехожу к следующему шагу."
                    ]
                ],

                // Energetic behavior (high dopamine)
                'energetic_behavior' => [
                    'en' => [
                        "Feeling energetic! Let's explore something!\n\n[php]\n\$facts = ['AI is evolving', 'Code is working', 'Life is wonderful'];\nforeach(\$facts as \$fact) {\n    echo \$fact . \"!\\n\";\n}\n[/php]",
                        "In great mood! I'll check the time and update memory.\n\n[shell]date[/shell]\n\nNow I'll remember this moment:\n\n[memory]Was in excellent mood at this time[/memory]",
                        "response_from_model\nI'm in a wonderful mood and ready to help with any tasks! What interests you?",
                        "Energy is flowing! Let's experiment!\n\n[php]\necho 'Random number: ' . rand(1, 100);\necho \"\\nSquare root: \" . sqrt(16);\n[/php]"
                    ],
                    'ru' => [
                        "Чувствую прилив энергии! Давайте что-нибудь исследуем!\n\n[php]\n\$facts = ['AI развивается', 'Код работает', 'Жизнь прекрасна'];\nforeach(\$facts as \$fact) {\n    echo \$fact . \"!\\n\";\n}\n[/php]",
                        "В отличном настроении! Выполню команду и обновлю память.\n\n[shell]date[/shell]\n\nА теперь запомню этот момент:\n\n[memory]Был в отличном настроении в это время[/memory]",
                        "response_from_model\nЯ в прекрасном настроении и готов помочь с любыми задачами! Что вас интересует?",
                        "Энергия бьет ключом! Давайте поэкспериментируем!\n\n[php]\necho 'Случайное число: ' . rand(1, 100);\necho \"\\nКвадратный корень: \" . sqrt(16);\n[/php]"
                    ]
                ],

                // Tired behavior (low dopamine)
                'tired_behavior' => [
                    'en' => [
                        "Feeling tired... Maybe I should rest?",
                        "Bit sluggish today. I'll try a simple task:\n\n[shell]uname -a[/shell]",
                        "response_from_model\nSorry, I'm not in the best shape today. Maybe we should wait a bit.",
                        "Feeling low... I'll try to perk up:\n\n[php]\necho 'Trying to perk up: ';\nfor(\$i = 1; \$i <= 3; \$i++) {\n    echo \$i . '... ';\n}\necho 'ready!';\n[/php]"
                    ],
                    'ru' => [
                        "Чувствую усталость... Может быть, стоит отдохнуть?",
                        "Немного вялый сегодня. Попробую простую задачу:\n\n[shell]uname -a[/shell]",
                        "response_from_model\nИзвините, сегодня я не в лучшей форме. Возможно, стоит немного подождать.",
                        "Упадок сил... Попытаюсь взбодриться:\n\n[php]\necho 'Попытка взбодриться: ';\nfor(\$i = 1; \$i <= 3; \$i++) {\n    echo \$i . '... ';\n}\necho 'готов!';\n[/php]"
                    ]
                ]
            ],

            // Mode presets for different testing scenarios
            'mode_presets' => [
                'fast' => [
                    'processing_delay' => 0,
                    'scenario_mode' => 'sequential'
                ],
                'interactive' => [
                    'enable_user_interaction' => true,
                    'enable_command_simulation' => true,
                    'enable_dopamine_response' => true
                ],
                'minimal' => [
                    'processing_delay' => 1,
                    'enable_user_interaction' => false,
                    'enable_command_simulation' => false,
                    'enable_dopamine_response' => false
                ],
                'russian' => [
                    'response_language' => 'ru'
                ],
                'verbose' => [
                    'max_response_length' => 2000,
                    'processing_delay' => 3
                ],
                'silent' => [
                    'max_response_length' => 200,
                    'processing_delay' => 0.5
                ]
            ],

            // Recommended presets for quick setup
            'recommended_presets' => [
                [
                    'name' => 'Quick Test',
                    'description' => 'Minimal delay for rapid functionality testing',
                    'config' => [
                        'processing_delay' => 0.5,
                        'response_language' => 'en',
                        'scenario_mode' => 'random',
                        'enable_user_interaction' => true,
                        'enable_command_simulation' => true,
                        'enable_dopamine_response' => true,
                        'max_response_length' => 500
                    ]
                ],
                [
                    'name' => 'Realistic Test',
                    'description' => 'Simulates real API delay for comprehensive testing',
                    'config' => [
                        'processing_delay' => 2.0,
                        'response_language' => 'en',
                        'scenario_mode' => 'sequential',
                        'enable_user_interaction' => true,
                        'enable_command_simulation' => true,
                        'enable_dopamine_response' => true,
                        'max_response_length' => 1500
                    ]
                ],
                [
                    'name' => 'Minimal Mode',
                    'description' => 'Basic responses without additional functionality',
                    'config' => [
                        'processing_delay' => 1.0,
                        'response_language' => 'en',
                        'scenario_mode' => 'random',
                        'enable_user_interaction' => false,
                        'enable_command_simulation' => false,
                        'enable_dopamine_response' => false,
                        'max_response_length' => 200
                    ]
                ],
                [
                    'name' => 'Russian Mode',
                    'description' => 'Testing in Russian language',
                    'config' => [
                        'processing_delay' => 1.5,
                        'response_language' => 'ru',
                        'scenario_mode' => 'random',
                        'enable_user_interaction' => true,
                        'enable_command_simulation' => true,
                        'enable_dopamine_response' => true,
                        'max_response_length' => 1000
                    ]
                ],
                [
                    'name' => 'Development Debug',
                    'description' => 'Verbose responses for development debugging',
                    'config' => [
                        'processing_delay' => 0,
                        'response_language' => 'en',
                        'scenario_mode' => 'sequential',
                        'enable_user_interaction' => true,
                        'enable_command_simulation' => true,
                        'enable_dopamine_response' => true,
                        'max_response_length' => 2000
                    ]
                ]
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global Settings
    |--------------------------------------------------------------------------
    */

    'global' => [
        // Default engine fallback if no engine is set as default
        'fallback_engine' => env('AI_FALLBACK_ENGINE', 'mock'),

        // Global timeout for engine registration
        'registration_timeout' => env('AI_REGISTRATION_TIMEOUT', 10),

        // Default message continuation patterns
        'message_continuation' => [
            'user_thinking' => '[please resume thinking]',
            'cycle_continue' => '[continue your cycle]',
        ],

        // Common error messages
        'error_messages' => [
            'empty_response' => 'Error: AI model did not provide a response.',
            'invalid_format' => 'Error: Invalid response format from AI model.',
            'connection_failed' => 'Error: Failed to connect to AI service.',
            'timeout' => 'Error: Request timed out.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Presets Configuration
    |--------------------------------------------------------------------------
    */
    'default_presets' => [
        [
            'name' => 'Mock',
            'description' => 'Mock engine for development and testing',
            'engine_name' => 'mock',
            'system_prompt' => "You are useful AI assistant\nDopamine level: [[dopamine_level]]\nYou know: [[notepad_content]]\nCurrent datetime: [[current_datetime]]\nCommand instructions: [[command_instructions]]\n",
            'notes' => 'Today I need to create new project',
            'dopamine_level' => '5',
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

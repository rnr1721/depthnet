<?php

use App\Services\Agent\Models\ClaudeModel;
use App\Services\Agent\Models\LlamaModel;
use App\Services\Agent\Models\MockModel;
use App\Services\Agent\Models\OpenAIModel;
use App\Services\Agent\Models\Phi3Model;

return [
    'models' => [
        'mock' => [
            'server_url' => 'http://localhost:8080',
            'class' => MockModel::class,
            'config' => [],
        ],
        'llama' => [
            'server_url' => env('LLAMA_SERVER_URL', 'http://localhost:8080'),
            'class' => LlamaModel::class,
            'config' => [
                'temperature' => (float) env('LLAMA_TEMPERATURE', 0.85),
                'top_p' => (float) env('LLAMA_TOP_P', 0.92),
                'top_k' => (int) env('LLAMA_TOP_K', 60),
                'min_p' => (float) env('LLAMA_MIN_P', 0.05),
                'n_predict' => (int) env('LLAMA_N_PREDICT', 600),
                'repeat_penalty' => (float) env('LLAMA_REPEAT_PENALTY', 1.18),
                'n' => 1
            ],
        ],
        'phi' => [
            'class' => Phi3Model::class,
            'server_url' => env('PHI_SERVER_URL', 'http://localhost:8080'),
            'config' => [
                'model' => 'phi3:mini',  // or phi3:medium
                'temperature' => (float) env('PHI_TEMPERATURE', 0.85),
            ]
        ],
        'claude' => [
            'class' => ClaudeModel::class,
            'server_url' => 'https://api.anthropic.com/v1/messages',
            'config' => [
                'api_key' => env('CLAUDE_API_KEY', ''),
                'model' => env('CLAUDE_MODEL', 'claude-3-5-sonnet-20241022'),
                'max_tokens' => (int) env('CLAUDE_MAX_TOKENS', 4096),
                'temperature' => (float) env('CLAUDE_TEMPERATURE', 0.8),
            ]
        ],
        'openai' => [
            'class' => OpenAIModel::class,
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'config' => [
                'api_key' => env('OPENAI_API_KEY', ''),
                'model' => env('OPENAI_MODEL', 'gpt-4o'),
                'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 4096),
                'temperature' => (float) env('OPENAI_TEMPERATURE', 0.8),
                'top_p' => (float) env('OPENAI_TOP_P', 0.9),
                'frequency_penalty' => (float) env('OPENAI_FREQUENCY_PENALTY', 0.0),
                'presence_penalty' => (float) env('OPENAI_PRESENCE_PENALTY', 0.0),
            ]
        ]
    ]
];

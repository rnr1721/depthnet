<?php

namespace App\Services\Agent\Providers;

use App\Contracts\Agent\AiModelRequestInterface;
use App\Contracts\Agent\AiModelResponseInterface;
use App\Contracts\Agent\Models\AIModelEngineInterface;
use App\Exceptions\AiModelException;
use App\Services\Agent\DTO\ModelResponseDTO;
use App\Services\Agent\Providers\Traits\AiModelPromptTrait;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Cache\CacheManager;
use Psr\Log\LoggerInterface;

/**
 * Claude AI engine implementation
 *
 * Supports Claude 3 family models via Anthropic API
 */
class ClaudeModel implements AIModelEngineInterface
{
    use AiModelPromptTrait;

    protected string $serverUrl;
    protected string $apiKey;
    protected string $model;

    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
        protected CacheManager $cache,
        protected array $config = []
    ) {
        // Get default config from global AI config
        $defaultConfig = config('ai.engines.claude', []);

        // Merge with provided config
        $this->config = array_merge($this->getDefaultConfig(), $defaultConfig, $config);

        $this->serverUrl = $this->config['server_url'];

        $this->apiKey = $config['api_key'] ?? $this->config['api_key'] ?? '';
        $this->model = $config['model'] ?? $this->config['model'];
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'claude';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return config('ai.engines.claude.display_name', 'Claude (Anthropic)');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return config(
            'ai.engines.claude.description',
            'Claude AI by Anthropic is a powerful language model with excellent analysis, creativity, and security capabilities. Supports the Claude 3 Haiku, Sonnet, and Opus models.'
        );
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        $models = config('ai.engines.claude.models', []);
        $validation = config('ai.engines.claude.validation', []);

        $modelOptions = [];
        foreach ($models as $modelId => $modelInfo) {
            $modelOptions[$modelId] = $modelInfo['display_name'] ?? $modelId;
        }

        return [
            'api_key' => [
                'type' => 'password',
                'label' => 'API key',
                'description' => 'API key from Anthropic Console',
                'placeholder' => 'sk-ant-api03-...',
                'required' => true
            ],
            'model' => [
                'type' => 'select',
                'label' => 'Model Claude',
                'description' => 'Select the Claude model to use',
                'options' => $modelOptions,
                'required' => true
            ],
            'temperature' => [
                'type' => 'number',
                'label' => 'Temperature',
                'description' => 'Controls the randomness of responses (0 = deterministic, 1 = creative)',
                'min' => $validation['temperature']['min'] ?? 0,
                'max' => $validation['temperature']['max'] ?? 1,
                'step' => 0.1,
                'required' => false
            ],
            'top_p' => [
                'type' => 'number',
                'label' => 'Top P',
                'description' => 'Nucleus sampling - temperature alternative',
                'min' => $validation['top_p']['min'] ?? 0,
                'max' => $validation['top_p']['max'] ?? 1,
                'step' => 0.05,
                'required' => false
            ],
            'max_tokens' => [
                'type' => 'number',
                'label' => 'Max tokens',
                'description' => 'Maximum number of tokens in response',
                'min' => $validation['max_tokens']['min'] ?? 1,
                'max' => $validation['max_tokens']['max'] ?? 8192,
                'required' => false
            ],
            'system_prompt' => [
                'type' => 'textarea',
                'label' => 'System prompt',
                'description' => 'Instructions for AI on how to behave and respond',
                'placeholder' => 'You are a useful AI assistant...',
                'required' => false,
                'rows' => 6
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function getRecommendedPresets(): array
    {
        // Get presets from config or use hardcoded fallback
        $configPresets = config('ai.engines.claude.recommended_presets', []);

        if (!empty($configPresets)) {
            return $configPresets;
        }

        // Fallback to default presets
        return [
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
        ];
    }

    /**
     * @inheritDoc
     */
    public function getDefaultConfig(): array
    {
        return [
            'model' => config('ai.engines.claude.model', 'claude-3-5-sonnet-20241022'),
            'max_tokens' => config('ai.engines.claude.max_tokens', 4096),
            'temperature' => config('ai.engines.claude.temperature', 0.8),
            'top_p' => config('ai.engines.claude.top_p', 0.9),
            'api_key' => config('ai.engines.claude.api_key', ''),
            'server_url' => config('ai.engines.claude.server_url', 'https://api.anthropic.com/v1/messages'),
            'system_prompt' => config('ai.engines.claude.system_prompt', 'You are a useful AI assistant. Answer in Russian.')
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        // Required fields
        if (empty($config['api_key'])) {
            $errors['api_key'] = 'API key is required';
        }

        // Validate model against supported models from config
        $supportedModels = array_keys(config('ai.engines.claude.models', []));

        if (isset($config['model']) && !empty($supportedModels) && !in_array($config['model'], $supportedModels)) {
            $errors['model'] = 'Unsupported model: ' . $config['model'] . '. Supported: ' . implode(', ', $supportedModels);
        }

        // Get validation rules from config
        $validation = config('ai.engines.claude.validation', []);

        // Validate temperature
        if (isset($config['temperature'])) {
            $temp = (float) $config['temperature'];
            $min = $validation['temperature']['min'] ?? 0;
            $max = $validation['temperature']['max'] ?? 1;

            if ($temp < $min || $temp > $max) {
                $errors['temperature'] = "Temperature must be between {$min} and {$max}";
            }
        }

        // Validate top_p
        if (isset($config['top_p'])) {
            $topP = (float) $config['top_p'];
            $min = $validation['top_p']['min'] ?? 0;
            $max = $validation['top_p']['max'] ?? 1;

            if ($topP < $min || $topP > $max) {
                $errors['top_p'] = "Top P must be between {$min} and {$max}";
            }
        }

        // Validate max_tokens
        if (isset($config['max_tokens'])) {
            $maxTokens = (int) $config['max_tokens'];
            $min = $validation['max_tokens']['min'] ?? 1;
            $max = $validation['max_tokens']['max'] ?? 8192;

            if ($maxTokens < $min || $maxTokens > $max) {
                $errors['max_tokens'] = "The maximum number of tokens must be between {$min} and {$max}";
            }
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function testConnection(): bool
    {
        try {
            $data = [
                'model' => $this->model,
                'max_tokens' => 10,
                'messages' => [
                    ['role' => 'user', 'content' => 'Hi']
                ]
            ];

            $headers = $this->getRequestHeaders();
            $timeout = config('ai.engines.claude.timeout', 10);

            $response = $this->http
                ->withHeaders($headers)
                ->timeout($timeout)
                ->post($this->serverUrl, $data);

            return $response->successful();

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function generate(
        AiModelRequestInterface $request
    ): AiModelResponseInterface {
        try {
            $messages = $this->buildMessages($request);

            $data = [
                'model' => $this->model,
                'max_tokens' => $this->config['max_tokens'],
                'temperature' => $this->config['temperature'],
                'top_p' => $this->config['top_p'],
                'messages' => $messages
            ];

            $headers = $this->getRequestHeaders();
            $timeout = config('ai.engines.claude.timeout', 120);

            $response = $this->http
                ->withHeaders($headers)
                ->timeout($timeout)
                ->post($this->serverUrl, $data);

            if ($response->failed()) {
                $errorBody = $response->json();
                $errorMessage = $errorBody['error']['message'] ?? config('ai.global.error_messages.connection_failed', 'Unknown error');
                throw new AiModelException("Claude API Error ({$response->status()}): $errorMessage");
            }

            $result = $response->json();

            if (!isset($result['content'][0]['text'])) {
                $this->logger->warning("Invalid Claude response format", ['response' => $result]);
                $errorMessage = config('ai.global.error_messages.invalid_format', 'Invalid response format from Claude API');
                throw new AiModelException($errorMessage);
            }

            return new ModelResponseDTO(
                $this->cleanOutput($result['content'][0]['text'])
            );
        } catch (\Exception $e) {
            $this->logger->error("ClaudeModel error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'context_size' => count($request->getContext()),
                'model' => $this->model
            ]);

            if (str_contains($e->getMessage(), 'API Error')) {
                return new ModelResponseDTO(
                    "error\nClaude API error: " . $e->getMessage(),
                    true
                );
            }

            return new ModelResponseDTO(
                "error\nError on Claude request: " . $e->getMessage(),
                true
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function updateConfig(array $newConfig): void
    {
        $this->config = array_merge($this->config, $newConfig);

        // Update the model if passed
        if (isset($newConfig['model'])) {
            $this->model = $newConfig['model'];
        }

        // Update API key if passed
        if (isset($newConfig['api_key'])) {
            $this->apiKey = $newConfig['api_key'];
        }
    }

    /**
     * @inheritDoc
     */
    public function getConfig(): array
    {
        return array_merge($this->config, [
            'model' => $this->model,
            'api_key' => $this->apiKey ? substr($this->apiKey, 0, 8) . '...' : '',
        ]);
    }

    /**
     * Get request headers from config
     */
    protected function getRequestHeaders(): array
    {
        $baseHeaders = config('ai.engines.claude.request_headers', [
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        ]);

        return array_merge($baseHeaders, [
            'x-api-key' => $this->apiKey,
        ]);
    }

    /**
     * Build messages array for Claude API
     * Note: ContextBuilders already ensure conversation ends with user message
     *
     * @param AiModelRequestInterface $request
     * @return array
     */
    protected function buildMessages(AiModelRequestInterface $request): array
    {
        $systemMessage = $this->prepareMessage($request);
        $messages = [];

        // Add system as first user message (Claude API feature)
        $messages[] = [
            'role' => 'user',
            'content' => $systemMessage
        ];

        $context = $request->getContext();

        foreach ($context as $entry) {
            $role = $entry['role'] ?? 'thinking';
            $content = $entry['content'] ?? '';

            if (empty(trim($content))) {
                continue;
            }

            // Map internal agent roles to Claude API roles
            switch ($role) {
                case 'user':
                    $messages[] = [
                        'role' => 'user',
                        'content' => $content
                    ];
                    break;
                case 'result':
                    $messages[] = [
                        'role' => 'system',
                        'content' => $content
                    ];
                    break;
                default:
                    // Agent messages (thinking, commands, responses)
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $content
                    ];
                    break;
            }
        }

        return $messages;
    }

    /**
     * Clean output from Claude using config patterns
     */
    protected function cleanOutput(?string $output): string
    {
        if (empty($output)) {
            $errorMessage = config('ai.global.error_messages.empty_response', 'Error: Claude did not provide an answer.');
            return "response_from_model\n{$errorMessage}";
        }

        $cleanOutput = trim($output);

        // Apply cleanup patterns from config
        $cleanupPattern = config('ai.engines.claude.cleanup.role_prefixes', '/^(Assistant|Claude):\s*/i');
        $cleanOutput = preg_replace($cleanupPattern, '', $cleanOutput);

        if (empty($cleanOutput)) {
            $errorMessage = config('ai.global.error_messages.empty_response', 'Error: Claude returned an empty response.');
            return "response_from_model\n{$errorMessage}";
        }

        return $cleanOutput;
    }

    /**
     * Set optimized settings for different modes
     */
    public function setMode(string $mode): void
    {
        // Get mode presets from config
        $modePresets = config('ai.engines.claude.mode_presets', []);

        if (isset($modePresets[$mode])) {
            $this->config = array_merge($this->config, $modePresets[$mode]);
            return;
        }

        // Fallback to hardcoded presets
        switch ($mode) {
            case 'creative':
                $this->config = array_merge($this->config, [
                    'temperature' => 1.0,
                    'top_p' => 0.95,
                    'max_tokens' => 4096
                ]);
                break;

            case 'focused':
                $this->config = array_merge($this->config, [
                    'temperature' => 0.3,
                    'top_p' => 0.8,
                    'max_tokens' => 2048
                ]);
                break;

            case 'balanced':
            default:
                $this->config = array_merge($this->config, [
                    'temperature' => 0.8,
                    'top_p' => 0.9,
                    'max_tokens' => 4096
                ]);
                break;
        }
    }

    /**
     * Get model information from config
     */
    public function getModelInfo(): array
    {
        $models = config('ai.engines.claude.models', []);
        return $models[$this->model] ?? [];
    }

    /**
     * Get maximum tokens for current model
     */
    public function getMaxTokens(): int
    {
        $modelInfo = $this->getModelInfo();
        return $modelInfo['max_tokens'] ?? 8192;
    }
}

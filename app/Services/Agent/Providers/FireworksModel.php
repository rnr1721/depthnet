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
 * Fireworks AI engine implementation
 *
 * Supports open-source models including LLaMA, Mistral, Code Llama, and others via Fireworks AI API
 * Compatible with OpenAI API standard for easy integration
 */
class FireworksModel implements AIModelEngineInterface
{
    use AiModelPromptTrait;

    protected string $serverUrl;
    protected string $apiKey;
    protected ?string $model;

    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
        protected CacheManager $cache,
        protected array $config = []
    ) {
        $defaultConfig = config('ai.engines.fireworks', []);

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
        return 'fireworks';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return config('ai.engines.fireworks.display_name', 'Fireworks AI');
    }

    /**
     * @inheritDoc
     */
    public function supportsDynamicModels(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function requiresApiKeyForModels(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return config(
            'ai.engines.fireworks.description',
            'Fireworks AI provides fast inference for open-source models including LLaMA, Mistral, Code Llama, and others. Optimized for production with high-speed inference and competitive pricing.'
        );
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(?array $presetConfig = null): array
    {

        $validation = config('ai.engines.fireworks.validation', []);

        return [
            'api_key' => [
                'type' => 'password',
                'label' => 'API Key',
                'description' => 'Get your API key from https://fireworks.ai/account/api-keys',
                'placeholder' => 'fw_...',
                'required' => true
            ],
            'model' => [
                'type' => 'dynamic_models',
                'label' => 'Model',
                'description' => 'Choose from available models',
                'depends_on' => 'api_key',
                'options' => [],
                'loading_text' => 'Loading available Claude models...',
                'error_text' => 'Failed to load models. Using fallback list.',
                'required' => true
            ],
            'temperature' => [
                'type' => 'number',
                'label' => 'Temperature',
                'description' => 'Controls randomness of responses (0 = deterministic, 2 = very creative)',
                'min' => $validation['temperature']['min'] ?? 0,
                'max' => $validation['temperature']['max'] ?? 2,
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
                'max' => $validation['max_tokens']['max'] ?? 16384,
                'required' => false
            ],
            'frequency_penalty' => [
                'type' => 'number',
                'label' => 'Frequency penalty',
                'description' => 'Reduces the likelihood of repeating frequently used tokens',
                'min' => $validation['frequency_penalty']['min'] ?? -2,
                'max' => $validation['frequency_penalty']['max'] ?? 2,
                'step' => 0.1,
                'required' => false
            ],
            'presence_penalty' => [
                'type' => 'number',
                'label' => 'Presence penalty',
                'description' => 'Reduces the likelihood of repeating already used tokens',
                'min' => $validation['presence_penalty']['min'] ?? -2,
                'max' => $validation['presence_penalty']['max'] ?? 2,
                'step' => 0.1,
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
        $configPresets = config('ai.engines.fireworks.recommended_presets', []);

        if (!empty($configPresets)) {
            return $configPresets;
        }

        return $this->getDefaultPresets();
    }

    /**
     * Get default presets (fallback)
     *
     * @return array
     */
    protected function getDefaultPresets(): array
    {
        return [
            [
                'name' => 'LLaMA 3.1 8B - Balanced',
                'description' => 'LLaMA 3.1 8B with balanced settings for general use',
                'config' => [
                    'model' => 'accounts/fireworks/models/llama-v3p1-8b-instruct',
                    'temperature' => 0.8,
                    'top_p' => 0.9,
                    'frequency_penalty' => 0.0,
                    'presence_penalty' => 0.0,
                    'max_tokens' => 2048,
                ]
            ],
            [
                'name' => 'LLaMA 3.1 70B - Power',
                'description' => 'LLaMA 3.1 70B for complex reasoning and analysis',
                'config' => [
                    'model' => 'accounts/fireworks/models/llama-v3p1-70b-instruct',
                    'temperature' => 0.7,
                    'top_p' => 0.9,
                    'frequency_penalty' => 0.0,
                    'presence_penalty' => 0.0,
                    'max_tokens' => 4096,
                ]
            ],
            [
                'name' => 'Mixtral 8x7B - Creative',
                'description' => 'Mixtral 8x7B optimized for creative writing and brainstorming',
                'config' => [
                    'model' => 'accounts/fireworks/models/mixtral-8x7b-instruct',
                    'temperature' => 1.0,
                    'top_p' => 0.95,
                    'frequency_penalty' => 0.2,
                    'presence_penalty' => 0.2,
                    'max_tokens' => 4096,
                ]
            ],
            [
                'name' => 'Yi 34B - Long Context',
                'description' => 'Yi 34B with 200k context for document analysis',
                'config' => [
                    'model' => 'accounts/fireworks/models/yi-34b-200k-capybara',
                    'temperature' => 0.6,
                    'top_p' => 0.9,
                    'frequency_penalty' => 0.0,
                    'presence_penalty' => 0.0,
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
            'model' => config('ai.engines.fireworks.model', 'accounts/fireworks/models/llama-v3p1-8b-instruct'),
            'max_tokens' => (int) config('ai.engines.fireworks.max_tokens', 2048),
            'temperature' => (float) config('ai.engines.fireworks.temperature', 0.8),
            'top_p' => (float) config('ai.engines.fireworks.top_p', 0.9),
            'frequency_penalty' => (float) config('ai.engines.fireworks.frequency_penalty', 0.0),
            'presence_penalty' => (float) config('ai.engines.fireworks.presence_penalty', 0.0),
            'api_key' => config('ai.engines.fireworks.api_key', ''),
            'server_url' => config('ai.engines.fireworks.server_url', 'https://api.fireworks.ai/inference/v1/chat/completions'),
            'models_endpoint' => config('ai.engines.fireworks.models_endpoint', 'https://api.fireworks.ai/inference/v1/models'),
            'system_prompt' => config('ai.engines.fireworks.system_prompt', 'You are a useful AI assistant.')
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

        if (empty($config['model'])) {
            $errors['model'] = 'Model name is required';
        }

        $validation = config('ai.engines.fireworks.validation', []);

        $numericFields = ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty', 'max_tokens'];

        foreach ($numericFields as $field) {
            if (isset($config[$field]) && isset($validation[$field])) {
                $value = is_numeric($config[$field]) ? (float) $config[$field] : null;
                $min = $validation[$field]['min'] ?? null;
                $max = $validation[$field]['max'] ?? null;

                if ($value !== null && $min !== null && $max !== null) {
                    if ($value < $min || $value > $max) {
                        $fieldName = ucfirst(str_replace('_', ' ', $field));
                        $errors[$field] = "{$fieldName} must be between {$min} and {$max}";
                    }
                }
            }
        }

        if (isset($config['server_url']) && !filter_var($config['server_url'], FILTER_VALIDATE_URL)) {
            $errors['server_url'] = 'The server URL must be a valid URL.';
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function testConnection(): bool
    {
        if (empty($this->apiKey)) {
            return false;
        }

        try {
            $modelsEndpoint = $this->config['models_endpoint'] ?? 'https://api.fireworks.ai/inference/v1/models';
            $headers = $this->getRequestHeaders();
            $timeout = config('ai.engines.fireworks.timeout', 10);

            $response = $this->http
                ->withHeaders($headers)
                ->timeout($timeout)
                ->get($modelsEndpoint);

            return $response->successful();

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get detailed connection test results
     *
     * @return array
     */
    public function testConnectionDetailed(): array
    {
        try {
            $endpoints = [
                'https://api.fireworks.ai/inference/v1/models',
                'https://api.fireworks.ai/v1/models'
            ];

            $headers = $this->getRequestHeaders();
            $timeout = config('ai.engines.fireworks.timeout', 10);

            foreach ($endpoints as $endpoint) {
                $this->logger->info('Testing Fireworks AI endpoint', [
                    'endpoint' => $endpoint,
                    'headers' => array_keys($headers)
                ]);

                $response = $this->http
                    ->withHeaders($headers)
                    ->timeout($timeout)
                    ->get($endpoint);

                if ($response->successful()) {
                    $models = $response->json();
                    return [
                        'success' => true,
                        'message' => 'Successfully connected to Fireworks AI.',
                        'endpoint_used' => $endpoint,
                        'available_models' => $models['data'] ?? [],
                        'total_models' => count($models['data'] ?? [])
                    ];
                }

                $this->logger->warning('Fireworks AI endpoint failed', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }

            return [
                'success' => false,
                'message' => 'All Fireworks AI endpoints failed',
                'endpoints_tried' => $endpoints
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to connect to Fireworks AI: ' . $e->getMessage(),
                'error_type' => get_class($e)
            ];
        }
    }

    /**
     * Get available models with Laravel caching
     * Note: Fireworks AI requires authentication to list models
     *
     * @param array|null $config
     * @return array
     */
    public function getAvailableModels(?array $config = null): array
    {

        $apiKey = $config['api_key'] ?? $this->apiKey ?? '';

        // Fireworks AI requires API key to list models, unlike some other providers
        if (empty($apiKey)) {
            $this->logger->info('No Fireworks AI API key provided, using fallback models (API requires auth)');
            return $this->getFallbackModels();
        }

        $cacheKey = 'fireworks_models_' . substr(md5($apiKey), 0, 8);
        $cacheLifetime = config('ai.engines.fireworks.models_cache_lifetime', 3600); // 1 hour

        return $this->cache->remember($cacheKey, $cacheLifetime, function () use ($apiKey) {
            try {
                $endpoints = [
                    $this->config['models_endpoint'] ?? 'https://api.fireworks.ai/inference/v1/models',
                    'https://api.fireworks.ai/v1/models'
                ];

                $headers = $this->getRequestHeaders();
                $timeout = config('ai.engines.fireworks.timeout', 30);

                foreach ($endpoints as $modelsEndpoint) {
                    $this->logger->info('Fetching Fireworks AI models from API', [
                        'endpoint' => $modelsEndpoint,
                        'headers' => array_keys($headers),
                        'api_key_prefix' => substr($apiKey, 0, 8) . '...'
                    ]);

                    $response = $this->http
                        ->withHeaders($headers)
                        ->timeout($timeout)
                        ->get($modelsEndpoint);

                    if ($response->successful()) {
                        $data = $response->json();
                        $models = [];

                        foreach ($data['data'] ?? [] as $model) {
                            $modelId = $model['id'] ?? '';
                            if (empty($modelId)) {
                                continue;
                            }

                            // Filter out non-chat models (embeddings, etc.)
                            if (str_contains($modelId, 'embedding') || str_contains($modelId, 'rerank')) {
                                continue;
                            }

                            $models[$modelId] = [
                                'id' => $modelId,
                                'display_name' => $this->formatModelDisplayName($modelId),
                                'context_length' => $model['context_length'] ?? null,
                                'created' => $model['created'] ?? null,
                                'owned_by' => $model['owned_by'] ?? 'fireworks',
                                'source' => 'api'
                            ];
                        }

                        if (!empty($models)) {

                            $modelOptions = [];
                            $recommendedModels = config('ai.engines.fireworks.recommended_models', [
                                'accounts/fireworks/models/llama-v3p1-8b-instruct',
                                'accounts/fireworks/models/llama-v3p1-70b-instruct',
                                'accounts/fireworks/models/mixtral-8x7b-instruct',
                                'accounts/fireworks/models/yi-34b-200k-capybara'
                            ]);

                            foreach ($recommendedModels as $modelId) {
                                if (isset($models[$modelId])) {
                                    $displayName = $models[$modelId]['display_name'] ?? $modelId;
                                    $source = $models[$modelId]['source'] ?? '';
                                    $label = $source === 'fallback' ? "{$displayName} (offline)" : $displayName;
                                    $modelOptions[$modelId] = "⭐ {$label}";
                                }
                            }

                            foreach ($models as $modelId => $modelInfo) {
                                if (!in_array($modelId, $recommendedModels)) {
                                    $displayName = $modelInfo['display_name'] ?? $modelId;
                                    $source = $modelInfo['source'] ?? '';
                                    $label = $source === 'fallback' ? "{$displayName} (offline)" : $displayName;
                                    $modelOptions[$modelId] = $label;
                                }
                            }

                            return $models;
                        }
                    }

                }

            } catch (\Exception $e) {
                $this->logger->error('Error fetching Fireworks AI models: ' . $e->getMessage(), [
                    'exception_type' => get_class($e),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            // If API call failed, still return fallback models
            $fallbackModels = $this->getFallbackModels();
            foreach ($fallbackModels as $modelId => $modelInfo) {
                $fallbackModels[$modelId]['source'] = 'fallback';
            }

            $this->logger->info('Using fallback models for Fireworks AI after API failure', ['count' => count($fallbackModels)]);
            return $fallbackModels;
        });
    }

    /**
     * Clear models cache
     *
     * @return void
     */
    public function clearModelsCache(): void
    {
        $cacheKey = 'fireworks_models_' . substr(md5($this->apiKey), 0, 8);
        $this->cache->forget($cacheKey);
    }

    /**
     * Get fallback models when API is unavailable
     *
     * @return array
     */
    protected function getFallbackModels(): array
    {
        return [
            'accounts/fireworks/models/llama-v3p1-8b-instruct' => [
                'id' => 'accounts/fireworks/models/llama-v3p1-8b-instruct',
                'display_name' => 'LLaMA 3.1 8B Instruct',
                'context_length' => 131072,
                'owned_by' => 'meta'
            ],
            'accounts/fireworks/models/llama-v3p1-70b-instruct' => [
                'id' => 'accounts/fireworks/models/llama-v3p1-70b-instruct',
                'display_name' => 'LLaMA 3.1 70B Instruct',
                'context_length' => 131072,
                'owned_by' => 'meta'
            ],
            'accounts/fireworks/models/mixtral-8x7b-instruct' => [
                'id' => 'accounts/fireworks/models/mixtral-8x7b-instruct',
                'display_name' => 'Mixtral 8x7B Instruct',
                'context_length' => 32768,
                'owned_by' => 'mistralai'
            ],
            'accounts/fireworks/models/yi-34b-200k-capybara' => [
                'id' => 'accounts/fireworks/models/yi-34b-200k-capybara',
                'display_name' => 'Yi 34B 200K Capybara',
                'context_length' => 200000,
                'owned_by' => '01-ai'
            ],
            'accounts/fireworks/models/llama-v3p1-405b-instruct' => [
                'id' => 'accounts/fireworks/models/llama-v3p1-405b-instruct',
                'display_name' => 'LLaMA 3.1 405B Instruct',
                'context_length' => 131072,
                'owned_by' => 'meta'
            ]
        ];
    }

    /**
     * Format model display name for better UX
     *
     * @param string $modelId
     * @return string
     */
    protected function formatModelDisplayName(string $modelId): string
    {
        $displayName = str_replace('accounts/fireworks/models/', '', $modelId);

        // Convert to human-readable format
        $displayName = str_replace(['-', '_'], ' ', $displayName);
        $displayName = ucwords($displayName);

        $displayName = str_replace([
            'Llama V3p1',
            'Mixtral',
            'Yi ',
            'Code Llama'
        ], [
            'LLaMA 3.1',
            'Mixtral',
            'Yi ',
            'Code Llama'
        ], $displayName);

        return $displayName;
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
                'messages' => $messages,
                'max_tokens' => (int) $this->config['max_tokens'],
                'temperature' => (float) $this->config['temperature'],
                'top_p' => (float) $this->config['top_p'],
                'frequency_penalty' => (float) $this->config['frequency_penalty'],
                'presence_penalty' => (float) $this->config['presence_penalty'],
                'stream' => false
            ];

            $headers = $this->getRequestHeaders();
            $timeout = (int) config('ai.engines.fireworks.timeout', 120);

            $response = $this->http
                ->withHeaders($headers)
                ->timeout($timeout)
                ->post($this->serverUrl, $data);

            if ($response->failed()) {
                $errorBody = $response->json();
                $errorMessage = $errorBody['error']['message'] ?? config('ai.global.error_messages.connection_failed', 'Unknown error');
                $errorCode = $errorBody['error']['code'] ?? 'unknown';
                throw new AiModelException("Fireworks AI API Error ({$response->status()}, {$errorCode}): $errorMessage");
            }

            $result = $response->json();

            if (!isset($result['choices'][0]['message']['content'])) {
                $this->logger->warning("Invalid Fireworks AI response format", [
                    'response' => $result,
                    'model' => $this->model
                ]);
                $errorMessage = config('ai.global.error_messages.invalid_format', 'Invalid response format from Fireworks AI API');
                throw new AiModelException($errorMessage);
            }

            // Log token usage if enabled
            if (isset($result['usage']) && config('ai.engines.fireworks.log_usage', true)) {
                $this->logger->info("Fireworks AI tokens used", [
                    'model' => $this->model,
                    'prompt_tokens' => $result['usage']['prompt_tokens'],
                    'completion_tokens' => $result['usage']['completion_tokens'],
                    'total_tokens' => $result['usage']['total_tokens']
                ]);
            }

            return new ModelResponseDTO(
                $this->cleanOutput($result['choices'][0]['message']['content'])
            );
        } catch (\Exception $e) {
            $this->logger->error("FireworksModel error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'context_size' => count($request->getContext()),
                'model' => $this->model
            ]);

            return $this->handleError($e);
        }
    }

    /**
     * Handle different types of Fireworks AI errors
     *
     * @param \Exception $e
     * @return ModelResponseDTO
     */
    protected function handleError(\Exception $e): ModelResponseDTO
    {
        $errorMessages = config('ai.engines.fireworks.error_messages', []);
        $errorMessage = $e->getMessage();

        $this->logger->error("Fireworks AI Error Details", [
            'error_message' => $errorMessage,
            'api_key_masked' => substr($this->apiKey, 0, 8) . '...',
            'model' => $this->model,
            'server_url' => $this->serverUrl
        ]);

        // Check for HTTP status codes in error message
        if (preg_match('/Fireworks AI API Error \((\d+),/', $errorMessage, $matches)) {
            $statusCode = intval($matches[1]);

            switch ($statusCode) {
                case 401:
                    $message = $errorMessages['invalid_api_key'] ?? "Invalid API key. Please check your Fireworks AI API key.";
                    break;
                case 403:
                    $message = $errorMessages['insufficient_quota'] ?? "Not enough Fireworks AI quota. Check your account balance.";
                    break;
                case 404:
                    $message = $errorMessages['invalid_model'] ?? "Model '{$this->model}' not found. Please choose a different model.";
                    break;
                case 429:
                    $message = $errorMessages['rate_limit'] ?? "Fireworks AI request limit exceeded. Please try again later.";
                    break;
                case 500:
                    $message = $errorMessages['model_unavailable'] ?? "Model temporarily unavailable. Please try again later.";
                    break;
                default:
                    $message = $errorMessages['api_error'] ?? "Error from Fireworks AI API: HTTP {$statusCode}";
            }

            return new ModelResponseDTO("error\n{$message}", true);
        }

        // Check for specific error types
        if (str_contains($errorMessage, 'rate_limit_exceeded')) {
            $message = $errorMessages['rate_limit'] ?? "Fireworks AI request limit exceeded. Please try again later.";
            return new ModelResponseDTO("error\n{$message}", true);
        }

        if (str_contains($errorMessage, 'insufficient_quota')) {
            $message = $errorMessages['insufficient_quota'] ?? "Not enough Fireworks AI quota. Check your account balance.";
            return new ModelResponseDTO("error\n{$message}", true);
        }

        if (str_contains($errorMessage, 'Connection refused') || str_contains($errorMessage, 'Could not resolve host')) {
            $message = $errorMessages['connection_failed'] ?? "Failed to connect to Fireworks AI. Please check your internet connection.";
            return new ModelResponseDTO("error\n{$message}", true);
        }

        if (str_contains($errorMessage, 'API Error')) {
            $message = $errorMessages['api_error'] ?? "Error from Fireworks AI API: " . $errorMessage;
            return new ModelResponseDTO("error\n{$message}", true);
        }

        $message = $errorMessages['general'] ?? "Error contacting Fireworks AI: " . $errorMessage;
        return new ModelResponseDTO("error\n{$message}", true);
    }

    /**
     * @inheritDoc
     */
    public function updateConfig(array $newConfig): void
    {
        $this->config = array_merge($this->config, $newConfig);

        if (isset($newConfig['model'])) {
            $this->model = $newConfig['model'];
        }

        if (isset($newConfig['api_key'])) {
            $this->apiKey = $newConfig['api_key'];
        }

        if (isset($newConfig['server_url'])) {
            $this->serverUrl = $newConfig['server_url'];
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
     *
     * @return array
     */
    protected function getRequestHeaders(): array
    {
        $baseHeaders = config('ai.engines.fireworks.request_headers', [
            'Content-Type' => 'application/json',
        ]);

        return array_merge($baseHeaders, [
            'Authorization' => 'Bearer ' . $this->apiKey,
        ]);
    }

    /**
     * Build messages array for Fireworks AI API
     *
     * @param AiModelRequestInterface $request
     * @return array
     */
    protected function buildMessages(AiModelRequestInterface $request): array
    {
        $systemMessage = $this->prepareMessage($request);
        $messages = [];

        $messages[] = [
            'role' => 'system',
            'content' => $systemMessage
        ];

        $context = $request->getContext();

        foreach ($context as $entry) {
            $role = $entry['role'] ?? 'assistant';
            $content = $entry['content'] ?? '';

            if (empty(trim($content))) {
                continue;
            }

            // Map roles for Fireworks AI (OpenAI-compatible)
            switch ($role) {
                case 'user':
                case 'command':
                    $messages[] = [
                        'role' => 'user',
                        'content' => $content
                    ];
                    break;
                case 'result':
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $content
                    ];
                    break;
                default:
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
     * Clean output using config patterns
     *
     * @param string|null $output
     * @return string
     */
    protected function cleanOutput(?string $output): string
    {
        if (empty($output)) {
            $errorMessage = config('ai.global.error_messages.empty_response', 'Error: Fireworks AI did not provide a response.');
            return "response_from_model\n{$errorMessage}";
        }

        $cleanOutput = trim($output);

        // Apply cleanup patterns from config
        $cleanupPattern = config('ai.engines.fireworks.cleanup.role_prefixes', '/^(Assistant|AI|Bot):\s*/i');
        $cleanOutput = preg_replace($cleanupPattern, '', $cleanOutput);

        if (empty($cleanOutput)) {
            $errorMessage = config('ai.global.error_messages.empty_response', 'Error: Fireworks AI returned an empty response.');
            return "response_from_model\n{$errorMessage}";
        }

        return $cleanOutput;
    }

    /**
     * Set optimized settings for different modes using config
     *
     * @param string $mode
     * @return void
     */
    public function setMode(string $mode): void
    {
        $modePresets = config('ai.engines.fireworks.mode_presets', []);

        if (isset($modePresets[$mode])) {
            $preset = $modePresets[$mode];
            foreach (['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] as $floatField) {
                if (isset($preset[$floatField])) {
                    $preset[$floatField] = (float) $preset[$floatField];
                }
            }
            if (isset($preset['max_tokens'])) {
                $preset['max_tokens'] = (int) $preset['max_tokens'];
            }

            $this->config = array_merge($this->config, $preset);
            return;
        }

        // Fallback to hardcoded presets
        switch ($mode) {
            case 'creative':
                $this->config = array_merge($this->config, [
                    'temperature' => 1.0,
                    'top_p' => 0.95,
                    'frequency_penalty' => 0.2,
                    'presence_penalty' => 0.2,
                    'max_tokens' => 4096
                ]);
                break;

            case 'focused':
                $this->config = array_merge($this->config, [
                    'temperature' => 0.2,
                    'top_p' => 0.8,
                    'frequency_penalty' => 0.0,
                    'presence_penalty' => 0.0,
                    'max_tokens' => 2048
                ]);
                break;

            case 'balanced':
            default:
                $this->config = array_merge($this->config, [
                    'temperature' => 0.8,
                    'top_p' => 0.9,
                    'frequency_penalty' => 0.0,
                    'presence_penalty' => 0.0,
                    'max_tokens' => 2048
                ]);
                break;
        }
    }

    /**
     * Get information about model limits from config or API
     *
     * @return array
     */
    public function getModelLimits(): array
    {
        $availableModels = $this->getAvailableModels();
        $modelInfo = $availableModels[$this->model] ?? [];

        return [
            'input' => $modelInfo['context_length'] ?? 32768,
            'output' => $this->config['max_tokens'] ?? 2048
        ];
    }

    /**
     * Get current model information
     *
     * @return array
     */
    public function getModelInfo(): array
    {
        $availableModels = $this->getAvailableModels();
        return $availableModels[$this->model] ?? [
            'id' => $this->model,
            'display_name' => $this->formatModelDisplayName($this->model),
            'owned_by' => 'fireworks'
        ];
    }

    /**
     * Check if current model is available
     *
     * @return boolean
     */
    public function isModelAvailable(): bool
    {
        $availableModels = $this->getAvailableModels();
        return isset($availableModels[$this->model]);
    }


    /**
     * Fetch models from API with user-provided API key
     * This method bypasses cache and fetches fresh model list
     *
     * @param string $userApiKey
     * @return array
     */
    public function fetchModelsFromAPI(string $userApiKey): array
    {
        try {
            $endpoints = [
                'https://api.fireworks.ai/inference/v1/models',
                'https://api.fireworks.ai/v1/models'
            ];

            $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $userApiKey,
                'User-Agent' => 'Laravel-AI-Agent/1.0',
            ];

            $timeout = config('ai.engines.fireworks.timeout', 30);

            foreach ($endpoints as $modelsEndpoint) {
                $this->logger->info('Fetching Fireworks AI models with user API key', [
                    'endpoint' => $modelsEndpoint,
                    'api_key_prefix' => substr($userApiKey, 0, 8) . '...'
                ]);

                $response = $this->http
                    ->withHeaders($headers)
                    ->timeout($timeout)
                    ->get($modelsEndpoint);

                if ($response->successful()) {
                    $data = $response->json();
                    $models = [];

                    foreach ($data['data'] ?? [] as $model) {
                        $modelId = $model['id'] ?? '';
                        if (empty($modelId)) {
                            continue;
                        }

                        // Filter out non-chat models
                        if (str_contains($modelId, 'embedding') || str_contains($modelId, 'rerank')) {
                            continue;
                        }

                        $models[$modelId] = [
                            'id' => $modelId,
                            'display_name' => $this->formatModelDisplayName($modelId),
                            'context_length' => $model['context_length'] ?? null,
                            'created' => $model['created'] ?? null,
                            'owned_by' => $model['owned_by'] ?? 'fireworks',
                            'source' => 'api'
                        ];
                    }

                    if (!empty($models)) {
                        $this->logger->info('Fireworks AI models fetched successfully', [
                            'endpoint' => $modelsEndpoint,
                            'count' => count($models)
                        ]);
                        return $models;
                    }
                }

                $this->logger->warning('Failed to fetch models from endpoint', [
                    'endpoint' => $modelsEndpoint,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Error fetching Fireworks AI models: ' . $e->getMessage(), [
                'exception_type' => get_class($e)
            ]);
        }

        return [];
    }

    /**
     * Get models suitable for specific tasks
     *
     * @param string $category
     * @return array
     */
    public function getModelsByCategory(string $category = 'general'): array
    {
        $availableModels = $this->getAvailableModels();
        $categorizedModels = [];

        foreach ($availableModels as $modelId => $modelInfo) {
            $matches = false;

            switch ($category) {
                case 'reasoning':
                    $matches = str_contains(strtolower($modelId), 'llama-v3p1-70b') ||
                              str_contains(strtolower($modelId), 'llama-v3p1-405b') ||
                              str_contains(strtolower($modelId), 'yi-34b');
                    break;
                case 'creative':
                    $matches = str_contains(strtolower($modelId), 'mixtral') ||
                              str_contains(strtolower($modelId), 'llama');
                    break;
                case 'coding':
                    $matches = str_contains(strtolower($modelId), 'code-llama') ||
                              str_contains(strtolower($modelId), 'deepseek-coder');
                    break;
                case 'long_context':
                    $matches = str_contains(strtolower($modelId), '200k') ||
                              str_contains(strtolower($modelId), 'yi-34b');
                    break;
                case 'general':
                default:
                    $matches = true;
                    break;
            }

            if ($matches) {
                $categorizedModels[$modelId] = $modelInfo;
            }
        }

        return $categorizedModels;
    }

    /**
     * Get maximum tokens for current model
     *
     * @return integer
     */
    public function getMaxTokens(): int
    {
        $modelInfo = $this->getModelInfo();
        return $modelInfo['context_length'] ?? 32768;
    }

    /**
     * Get model pricing information (if available)
     *
     * @return array
     */
    public function getModelPricing(): array
    {
        // Fireworks AI pricing per million tokens (as of 2024)
        $pricing = [
            'accounts/fireworks/models/llama-v3p1-8b-instruct' => [
                'input' => 0.2,
                'output' => 0.2
            ],
            'accounts/fireworks/models/llama-v3p1-70b-instruct' => [
                'input' => 0.9,
                'output' => 0.9
            ],
            'accounts/fireworks/models/llama-v3p1-405b-instruct' => [
                'input' => 3.0,
                'output' => 3.0
            ],
            'accounts/fireworks/models/mixtral-8x7b-instruct' => [
                'input' => 0.5,
                'output' => 0.5
            ]
        ];

        return $pricing[$this->model] ?? [
            'input' => null,
            'output' => null
        ];
    }
}

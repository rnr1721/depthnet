<?php

namespace App\Services\Agent\Providers;

use App\Contracts\Agent\AiModelRequestInterface;
use App\Contracts\Agent\AiModelResponseInterface;
use App\Contracts\Agent\Models\AIModelEngineInterface;
use App\Exceptions\AiModelException;
use App\Services\Agent\DTO\ModelResponseDTO;
use App\Services\Agent\Providers\Traits\AiModelPromptTrait;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * OpenAI GPT engine implementation
 *
 * Supports GPT-4, GPT-4o, GPT-3.5-turbo and o1 models via OpenAI API
 */
class OpenAIModel implements AIModelEngineInterface
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
        $defaultConfig = config('ai.engines.openai', []);

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
        return 'openai';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return config('ai.engines.openai.display_name', 'OpenAI GPT');
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
            'ai.engines.openai.description',
            'OpenAI GPT модели - самые популярные и мощные языковые модели. Включает GPT-4o, GPT-4 Turbo, GPT-3.5 Turbo и модели рассуждений o1. Отличается высоким качеством и надежностью.'
        );
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        $models = config('ai.engines.openai.models', []);
        $validation = config('ai.engines.openai.validation', []);

        $modelOptions = [];
        foreach ($models as $modelId => $modelInfo) {
            $modelOptions[$modelId] = $modelInfo['display_name'] ?? $modelId;
        }

        return [
            'api_key' => [
                'type' => 'password',
                'label' => 'API key',
                'description' => 'API key from OpenAI Platform',
                'placeholder' => 'sk-...',
                'required' => true
            ],
            'model' => [
                'type' => 'select',
                'label' => 'GPT model',
                'description' => 'Select an OpenAI model to use',
                'options' => $modelOptions,
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
                'max' => $validation['max_tokens']['max'] ?? 128000,
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
    public function getAvailableModels(?array $config = null): array
    {
        $apiKey = $config['api_key'] ?? $this->apiKey ?? '';

        // OpenAI requires API key for model listing
        if (empty($apiKey)) {
            $this->logger->info('No OpenAI API key provided, using fallback models');
            return $this->getFallbackModels();
        }

        $cacheKey = 'openai_models_' . substr(md5($apiKey), 0, 8);
        $cacheLifetime = config('ai.engines.openai.models_cache_lifetime', 3600); // 1 hour

        return $this->cache->remember($cacheKey, $cacheLifetime, function () use ($apiKey) {
            try {
                $modelsEndpoint = config('ai.engines.openai.models_endpoint', 'https://api.openai.com/v1/models');

                $headers = [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ];

                $timeout = config('ai.engines.openai.timeout', 30);

                $this->logger->info('Fetching OpenAI models from API', [
                    'endpoint' => $modelsEndpoint,
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

                        // Filter only chat completion models
                        if (!$this->isChatModel($modelId)) {
                            continue;
                        }

                        $models[$modelId] = [
                            'id' => $modelId,
                            'display_name' => $this->formatModelDisplayName($modelId),
                            'description' => $this->getModelDescription($modelId),
                            'context_length' => $this->getModelContextLength($modelId),
                            'owned_by' => $model['owned_by'] ?? 'openai',
                            'category' => $this->categorizeModel($modelId),
                            'recommended' => $this->isRecommendedModel($modelId),
                            'created' => $model['created'] ?? null,
                            'source' => 'api'
                        ];
                    }

                    // Sort models with recommended first
                    uasort($models, function ($a, $b) {
                        if ($a['recommended'] !== $b['recommended']) {
                            return $b['recommended'] <=> $a['recommended'];
                        }
                        return strcmp($a['display_name'], $b['display_name']);
                    });

                    if (!empty($models)) {
                        $this->logger->info('OpenAI models loaded from API', [
                            'count' => count($models)
                        ]);
                        return $models;
                    }
                }

                $this->logger->warning('Failed to fetch OpenAI models from API', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);

            } catch (\Exception $e) {
                $this->logger->error('Error fetching OpenAI models: ' . $e->getMessage(), [
                    'exception_type' => get_class($e)
                ]);
            }

            // Return fallback models if API fails
            $fallbackModels = $this->getFallbackModels();
            foreach ($fallbackModels as $modelId => $modelInfo) {
                $fallbackModels[$modelId]['source'] = 'fallback';
            }

            $this->logger->info('Using fallback models for OpenAI after API failure', ['count' => count($fallbackModels)]);
            return $fallbackModels;
        });
    }

    /**
     * Get model context length
     */
    protected function getModelContextLength(string $modelId): int
    {
        $contextLengths = [
            'gpt-4o' => 128000,
            'gpt-4o-mini' => 128000,
            'gpt-4-turbo' => 128000,
            'gpt-4' => 8192,
            'gpt-3.5-turbo' => 16385,
            'o1-preview' => 128000,
            'o1-mini' => 128000
        ];

        // Handle versioned models
        foreach ($contextLengths as $model => $length) {
            if (str_starts_with($modelId, $model)) {
                return $length;
            }
        }

        return 4096; // default
    }

    /**
     * Categorize model by capabilities
     */
    protected function categorizeModel(string $modelId): string
    {
        if (str_contains($modelId, 'o1')) {
            return 'reasoning';
        }

        if (str_contains($modelId, 'gpt-4')) {
            return 'general';
        }

        if (str_contains($modelId, 'gpt-3.5')) {
            return 'general';
        }

        return 'general';
    }

    /**
     * Get fallback model options for select field
     */
    protected function getFallbackModelOptions(): array
    {
        $models = $this->getFallbackModels();
        $options = [];

        foreach ($models as $modelId => $modelInfo) {
            $prefix = $modelInfo['recommended'] ? '⭐ ' : '';
            $options[$modelId] = $prefix . $modelInfo['display_name'];
        }

        return $options;
    }

    /**
     * Check if model is recommended
     */
    protected function isRecommendedModel(string $modelId): bool
    {
        $recommendedModels = [
            'gpt-4o',
            'gpt-4o-mini',
            'gpt-4-turbo'
        ];

        return in_array($modelId, $recommendedModels);
    }

    /**
     * Check if model is suitable for chat completion
     */
    protected function isChatModel(string $modelId): bool
    {
        // Filter out non-chat models
        $excludePatterns = [
            'whisper',
            'tts',
            'dall-e',
            'davinci',
            'curie',
            'babbage',
            'ada',
            'embedding',
            'text-moderation',
            'text-similarity',
            'text-search',
            'code-search'
        ];

        foreach ($excludePatterns as $pattern) {
            if (str_contains(strtolower($modelId), $pattern)) {
                return false;
            }
        }

        // Include known chat models
        $chatPatterns = [
            'gpt-3.5',
            'gpt-4',
            'o1',
            'chatgpt'
        ];

        foreach ($chatPatterns as $pattern) {
            if (str_contains(strtolower($modelId), $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format model display name
     */
    protected function formatModelDisplayName(string $modelId): string
    {
        // Handle special cases
        $specialNames = [
            'gpt-4o' => 'GPT-4o',
            'gpt-4o-mini' => 'GPT-4o Mini',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'gpt-4' => 'GPT-4',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
            'o1-preview' => 'o1 Preview',
            'o1-mini' => 'o1 Mini'
        ];

        if (isset($specialNames[$modelId])) {
            return $specialNames[$modelId];
        }

        // General formatting
        $displayName = str_replace(['-', '_'], ' ', $modelId);
        $displayName = ucwords($displayName);

        // Handle GPT naming
        $displayName = preg_replace('/Gpt (\d+)/', 'GPT-$1', $displayName);
        $displayName = str_replace('Gpt', 'GPT', $displayName);

        return $displayName;
    }

    /**
     * Get model description based on known models
     */
    protected function getModelDescription(string $modelId): ?string
    {
        $descriptions = [
            'gpt-4o' => 'Latest GPT-4 Omni model with multimodal capabilities',
            'gpt-4o-mini' => 'Smaller, faster version of GPT-4o',
            'gpt-4-turbo' => 'Latest GPT-4 Turbo model with improved performance',
            'gpt-4' => 'Original GPT-4 model with advanced reasoning',
            'gpt-3.5-turbo' => 'Fast and cost-effective model for most tasks',
            'o1-preview' => 'Advanced reasoning model optimized for complex problems',
            'o1-mini' => 'Faster reasoning model for coding and math'
        ];

        return $descriptions[$modelId] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function getRecommendedPresets(): array
    {
        // Get presets from config or use hardcoded fallback
        $configPresets = config('ai.engines.openai.recommended_presets', []);

        if (!empty($configPresets)) {
            return $configPresets;
        }

        // Fallback to default presets (keeping existing logic)
        return $this->getDefaultPresets();
    }

    /**
     * Get default presets (fallback)
     */
    protected function getDefaultPresets(): array
    {
        return [
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
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function getDefaultConfig(): array
    {
        return [
            'model' => config('ai.engines.openai.model', 'gpt-4o'),
            'max_tokens' => (int) config('ai.engines.openai.max_tokens', 4096),
            'temperature' => (float) config('ai.engines.openai.temperature', 0.8),
            'top_p' => (float) config('ai.engines.openai.top_p', 0.9),
            'frequency_penalty' => (float) config('ai.engines.openai.frequency_penalty', 0.0),
            'presence_penalty' => (float) config('ai.engines.openai.presence_penalty', 0.0),
            'api_key' => config('ai.engines.openai.api_key', ''),
            'server_url' => config('ai.engines.openai.server_url', 'https://api.openai.com/v1/chat/completions'),
            'system_prompt' => config('ai.engines.openai.system_prompt', 'You are a useful AI assistant.')
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
        $supportedModels = array_keys(config('ai.engines.openai.models', []));

        if (isset($config['model']) && !empty($supportedModels) && !in_array($config['model'], $supportedModels)) {
            $errors['model'] = 'Unsupported model: ' . $config['model'] . '. Supported: ' . implode(', ', $supportedModels);
        }

        // Get validation rules from config
        $validation = config('ai.engines.openai.validation', []);

        // Validate numeric parameters using config ranges
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

        // Validate server_url
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
        try {
            $data = [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'user', 'content' => 'Hi']
                ],
                'max_tokens' => 5
            ];

            $headers = $this->getRequestHeaders();
            $timeout = config('ai.engines.openai.timeout', 10);

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
                'messages' => $messages,
                'max_tokens' => (int) $this->config['max_tokens'],
                'temperature' => (float) $this->config['temperature'],
                'top_p' => (float) $this->config['top_p'],
                'frequency_penalty' => (float) $this->config['frequency_penalty'],
                'presence_penalty' => (float) $this->config['presence_penalty'],
                'stream' => false
            ];

            $headers = $this->getRequestHeaders();
            $timeout = config('ai.engines.openai.timeout', 120);

            $response = $this->http
                ->withHeaders($headers)
                ->timeout($timeout)
                ->post($this->serverUrl, $data);

            if ($response->failed()) {
                $errorBody = $response->json();
                $errorMessage = $errorBody['error']['message'] ?? config('ai.global.error_messages.connection_failed', 'Unknown error');
                $errorCode = $errorBody['error']['code'] ?? 'unknown';
                throw new AiModelException("OpenAI API Error ({$response->status()}, {$errorCode}): $errorMessage");
            }

            $result = $response->json();

            if (!isset($result['choices'][0]['message']['content'])) {
                $this->logger->warning("Invalid OpenAI response format", ['response' => $result]);
                $errorMessage = config('ai.global.error_messages.invalid_format', 'Invalid response format from OpenAI API');
                throw new AiModelException($errorMessage);
            }

            // Log token usage if enabled
            if (isset($result['usage']) && config('ai.engines.openai.log_usage', true)) {
                $this->logger->info("OpenAI tokens used", [
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
            $this->logger->error("OpenAIModel error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'context_size' => count($request->getContext()),
                'model' => $this->model
            ]);

            return $this->handleError($e);
        }
    }

    /**
     * Handle different types of OpenAI errors
     */
    protected function handleError(\Exception $e): ModelResponseDTO
    {
        $errorMessages = config('ai.engines.openai.error_messages', []);

        // Check for specific error types
        if (str_contains($e->getMessage(), 'rate_limit_exceeded')) {
            $message = $errorMessages['rate_limit'] ?? "OpenAI request limit exceeded. Please try again later.";
            return new ModelResponseDTO("error\n{$message}", true);
        }

        if (str_contains($e->getMessage(), 'insufficient_quota')) {
            $message = $errorMessages['insufficient_quota'] ?? "Not enough OpenAI quota. Check your account balance.";
            return new ModelResponseDTO("error\n{$message}", true);
        }

        if (str_contains($e->getMessage(), 'API Error')) {
            $message = $errorMessages['api_error'] ?? "Error from OpenAI API: " . $e->getMessage();
            return new ModelResponseDTO("error\n{$message}", true);
        }

        $message = $errorMessages['general'] ?? "Error contacting OpenAI: " . $e->getMessage();
        return new ModelResponseDTO("error\n{$message}", true);
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

        // Update server URL if passed
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
     */
    protected function getRequestHeaders(): array
    {
        $baseHeaders = config('ai.engines.openai.request_headers', [
            'Content-Type' => 'application/json',
        ]);

        return array_merge($baseHeaders, [
            'Authorization' => 'Bearer ' . $this->apiKey,
        ]);
    }

    /**
     * Build messages array for OpenAI API
     *
     * @param AiModelRequestInterface $request
     * @return array
     */
    protected function buildMessages(AiModelRequestInterface $request): array
    {
        $systemMessage = $this->prepareMessage($request);
        $messages = [];

        // OpenAI uses system role
        $messages[] = [
            'role' => 'system',
            'content' => $systemMessage
        ];

        $context = $request->getContext();
        $lastRole = null;

        foreach ($context as $entry) {
            $role = $entry['role'] ?? 'assistant';
            $content = $entry['content'] ?? '';

            if (empty(trim($content))) {
                continue;
            }

            // Map roles for OpenAI
            switch ($role) {
                case 'user':
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
     */
    protected function cleanOutput(?string $output): string
    {
        if (empty($output)) {
            $errorMessage = config('ai.global.error_messages.empty_response', 'Error: OpenAI did not provide a response.');
            return "response_from_model\n{$errorMessage}";
        }

        $cleanOutput = trim($output);

        // Apply cleanup patterns from config
        $cleanupPattern = config('ai.engines.openai.cleanup.role_prefixes', '/^(Assistant|AI|GPT):\s*/i');
        $cleanOutput = preg_replace($cleanupPattern, '', $cleanOutput);

        if (empty($cleanOutput)) {
            $errorMessage = config('ai.global.error_messages.empty_response', 'Error: OpenAI returned an empty response.');
            return "response_from_model\n{$errorMessage}";
        }

        return $cleanOutput;
    }

    /**
     * Set optimized settings for different modes using config
     */
    public function setMode(string $mode): void
    {
        // Get mode presets from config
        $modePresets = config('ai.engines.openai.mode_presets', []);

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
                    'max_tokens' => 4096
                ]);
                break;
        }
    }

    /**
     * Get information about model limits from config
     */
    public function getModelLimits(): array
    {
        $models = config('ai.engines.openai.models', []);
        $modelInfo = $models[$this->model] ?? [];

        return [
            'input' => $modelInfo['input_limit'] ?? 16385,
            'output' => $modelInfo['output_limit'] ?? 4096
        ];
    }

    /**
     * Get the cost of using the model from config
     */
    public function getModelPricing(): array
    {
        $models = config('ai.engines.openai.models', []);
        $modelInfo = $models[$this->model] ?? [];

        return $modelInfo['pricing'] ?? ['input' => 0.001, 'output' => 0.002];
    }

    /**
     * Get model information from config
     */
    public function getModelInfo(): array
    {
        $models = config('ai.engines.openai.models', []);
        return $models[$this->model] ?? [];
    }

    /**
     * Check model availability
     */
    public function isModelAvailable(): bool
    {
        try {
            $modelsEndpoint = config('ai.engines.openai.models_endpoint', 'https://api.openai.com/v1/models');
            $timeout = config('ai.engines.openai.timeout', 10);

            $response = $this->http
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ])
                ->timeout($timeout)
                ->get($modelsEndpoint);

            if ($response->successful()) {
                $models = $response->json();
                $availableModels = collect($models['data'])->pluck('id')->toArray();
                return in_array($this->model, $availableModels);
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->warning("Failed to check OpenAI model availability: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get fallback models when API is unavailable
     */
    protected function getFallbackModels(): array
    {
        return [
            'gpt-4o' => [
                'id' => 'gpt-4o',
                'display_name' => 'GPT-4o',
                'description' => 'Latest GPT-4 Omni model with multimodal capabilities',
                'context_length' => 128000,
                'owned_by' => 'openai',
                'category' => 'general',
                'recommended' => true
            ],
            'gpt-4o-mini' => [
                'id' => 'gpt-4o-mini',
                'display_name' => 'GPT-4o Mini',
                'description' => 'Smaller, faster version of GPT-4o',
                'context_length' => 128000,
                'owned_by' => 'openai',
                'category' => 'general',
                'recommended' => true
            ],
            'gpt-4-turbo' => [
                'id' => 'gpt-4-turbo',
                'display_name' => 'GPT-4 Turbo',
                'description' => 'Latest GPT-4 Turbo model',
                'context_length' => 128000,
                'owned_by' => 'openai',
                'category' => 'general',
                'recommended' => true
            ],
            'gpt-4' => [
                'id' => 'gpt-4',
                'display_name' => 'GPT-4',
                'description' => 'Original GPT-4 model',
                'context_length' => 8192,
                'owned_by' => 'openai',
                'category' => 'reasoning',
                'recommended' => false
            ],
            'gpt-3.5-turbo' => [
                'id' => 'gpt-3.5-turbo',
                'display_name' => 'GPT-3.5 Turbo',
                'description' => 'Fast and cost-effective model',
                'context_length' => 16385,
                'owned_by' => 'openai',
                'category' => 'general',
                'recommended' => false
            ],
            'o1-preview' => [
                'id' => 'o1-preview',
                'display_name' => 'o1 Preview',
                'description' => 'Advanced reasoning model (preview)',
                'context_length' => 128000,
                'owned_by' => 'openai',
                'category' => 'reasoning',
                'recommended' => false
            ],
            'o1-mini' => [
                'id' => 'o1-mini',
                'display_name' => 'o1 Mini',
                'description' => 'Faster reasoning model',
                'context_length' => 128000,
                'owned_by' => 'openai',
                'category' => 'reasoning',
                'recommended' => false
            ]
        ];
    }

}

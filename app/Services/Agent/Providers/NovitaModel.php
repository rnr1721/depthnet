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
 * Novita AI engine implementation
 *
 * Supports 200+ models including LLaMA, Mistral, DeepSeek, Qwen and others via Novita AI API
 * Compatible with OpenAI API standard for easy integration
 */
class NovitaModel implements AIModelEngineInterface
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
        $defaultConfig = config('ai.engines.novita', []);

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
        return 'novita';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return config('ai.engines.novita.display_name', 'Novita AI');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return config(
            'ai.engines.novita.description',
            'Novita AI provides access to 200+ open-source models including LLaMA, Mistral, DeepSeek, Qwen and others. Fast, reliable and cost-effective inference with up to 300 tokens/sec. Compatible with OpenAI API standard.'
        );
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        $availableModels = $this->getAvailableModels();
        $validation = config('ai.engines.novita.validation', []);

        $modelOptions = [];
        $recommendedModels = config('ai.engines.novita.recommended_models', [
            'meta-llama/llama-3.1-8b-instruct',
            'meta-llama/llama-3.1-70b-instruct',
            'deepseek/deepseek-r1'
        ]);

        foreach ($recommendedModels as $modelId) {
            if (isset($availableModels[$modelId])) {
                $displayName = $availableModels[$modelId]['display_name'] ?? $modelId;
                $source = $availableModels[$modelId]['source'] ?? '';
                $label = $source === 'fallback' ? "{$displayName} (offline)" : $displayName;
                $modelOptions[$modelId] = "⭐ {$label}";
            }
        }

        foreach ($availableModels as $modelId => $modelInfo) {
            if (!in_array($modelId, $recommendedModels)) {
                $displayName = $modelInfo['display_name'] ?? $modelId;
                $source = $modelInfo['source'] ?? '';
                $label = $source === 'fallback' ? "{$displayName} (offline)" : $displayName;
                $modelOptions[$modelId] = $label;
            }
        }

        return [
            'api_key' => [
                'type' => 'password',
                'label' => 'API Key',
                'description' => 'Get your API key from https://novita.ai/settings/key-management',
                'placeholder' => 'nvapi-...',
                'required' => true
            ],
            'model' => [
                'type' => 'select',
                'label' => 'Model',
                'description' => 'Choose from available models',
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
                'max' => $validation['max_tokens']['max'] ?? 32768,
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
        // Get presets from config or use hardcoded fallback
        $configPresets = config('ai.engines.novita.recommended_presets', []);

        if (!empty($configPresets)) {
            return $configPresets;
        }

        // Fallback to default presets
        return $this->getDefaultPresets();
    }

    /**
     * Get default presets (fallback)
     */
    protected function getDefaultPresets(): array
    {
        return [
            [
                'name' => 'LLaMA 3.1 8B - Balanced',
                'description' => 'Meta LLaMA 3.1 8B with balanced settings for general use',
                'config' => [
                    'model' => 'meta-llama/llama-3.1-8b-instruct',
                    'temperature' => 0.8,
                    'top_p' => 0.9,
                    'frequency_penalty' => 0.0,
                    'presence_penalty' => 0.0,
                    'max_tokens' => 2048,
                ]
            ],
            [
                'name' => 'DeepSeek R1 - Creative',
                'description' => 'DeepSeek R1 optimized for creative and reasoning tasks',
                'config' => [
                    'model' => 'deepseek/deepseek-r1',
                    'temperature' => 1.0,
                    'top_p' => 0.95,
                    'frequency_penalty' => 0.2,
                    'presence_penalty' => 0.2,
                    'max_tokens' => 4096,
                ]
            ],
            [
                'name' => 'Mistral 7B - Focused',
                'description' => 'Mistral 7B with focused settings for accurate responses',
                'config' => [
                    'model' => 'mistralai/mistral-7b-instruct',
                    'temperature' => 0.3,
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
            'model' => config('ai.engines.novita.model', 'meta-llama/llama-3.1-8b-instruct'),
            'max_tokens' => (int) config('ai.engines.novita.max_tokens', 2048),
            'temperature' => (float) config('ai.engines.novita.temperature', 0.8),
            'top_p' => (float) config('ai.engines.novita.top_p', 0.9),
            'frequency_penalty' => (float) config('ai.engines.novita.frequency_penalty', 0.0),
            'presence_penalty' => (float) config('ai.engines.novita.presence_penalty', 0.0),
            'api_key' => config('ai.engines.novita.api_key', ''),
            'server_url' => config('ai.engines.novita.server_url', 'https://api.novita.ai/v3/openai/chat/completions'),
            'models_endpoint' => config('ai.engines.novita.models_endpoint', 'https://api.novita.ai/v3/openai/models'),
            'system_prompt' => config('ai.engines.novita.system_prompt', 'You are a useful AI assistant.')
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

        // Get validation rules from config
        $validation = config('ai.engines.novita.validation', []);

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
            $modelsEndpoint = $this->config['models_endpoint'] ?? 'https://api.novita.ai/v3/openai/models';
            $headers = $this->getRequestHeaders();
            $timeout = config('ai.engines.novita.timeout', 10);

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
     */
    public function testConnectionDetailed(): array
    {
        try {

            $modelsEndpoint = $this->config['models_endpoint'] ?? 'https://api.novita.ai/v3/openai/models';
            $headers = $this->getRequestHeaders();
            $timeout = config('ai.engines.novita.timeout', 10);

            $response = $this->http
                ->withHeaders($headers)
                ->timeout($timeout)
                ->get($modelsEndpoint);

            if ($response->successful()) {
                $models = $response->json();
                return [
                    'success' => true,
                    'message' => 'Successfully connected to Novita AI.',
                    'available_models' => $models['data'] ?? [],
                    'total_models' => count($models['data'] ?? [])
                ];
            }

            return [
                'success' => false,
                'message' => 'Novita AI responded with an error: ' . $response->status(),
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to connect to Novita AI: ' . $e->getMessage(),
                'error_type' => get_class($e)
            ];
        }
    }

    /**
     * Get available models with Laravel caching
     */
    public function getAvailableModels(): array
    {
        // If no API key, return fallback immediately
        if (empty($this->apiKey)) {
            $this->logger->warning('No Novita AI API key provided, using fallback models');
            return $this->getFallbackModels();
        }

        $cacheKey = 'novita_models_' . substr(md5($this->apiKey), 0, 8);
        $cacheLifetime = config('ai.engines.novita.models_cache_lifetime', 3600); // 30 min

        return $this->cache->remember($cacheKey, $cacheLifetime, function () {
            try {
                $modelsEndpoint = $this->config['models_endpoint'] ?? 'https://api.novita.ai/v3/openai/models';
                $headers = $this->getRequestHeaders();
                $timeout = config('ai.engines.novita.timeout', 30);

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
                        } // Skip invalid models

                        $models[$modelId] = [
                            'id' => $modelId,
                            'display_name' => $this->formatModelDisplayName($modelId),
                            'context_length' => $model['context_length'] ?? null,
                            'created' => $model['created'] ?? null,
                            'owned_by' => $model['owned_by'] ?? 'novita',
                            'source' => 'api' // Mark as API source
                        ];
                    }

                    if (!empty($models)) {
                        $this->logger->info('Novita AI models loaded from API', ['count' => count($models)]);
                        return $models;
                    }
                }

                $this->logger->warning('Failed to fetch Novita AI models from API', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);

            } catch (\Exception $e) {
                $this->logger->error('Error fetching Novita AI models: ' . $e->getMessage(), [
                    'exception_type' => get_class($e)
                ]);
            }

            // Fallback with source marking
            $fallbackModels = $this->getFallbackModels();
            foreach ($fallbackModels as $modelId => $modelInfo) {
                $fallbackModels[$modelId]['source'] = 'fallback';
            }

            $this->logger->info('Using fallback models for Novita AI', ['count' => count($fallbackModels)]);
            return $fallbackModels;
        });
    }

    /**
     * Clear models cache (useful for admin panel)
     */
    public function clearModelsCache(): void
    {
        $cacheKey = 'novita_models_' . substr(md5($this->apiKey), 0, 8);
        $this->cache->forget($cacheKey);
    }

    /**
     * Get fallback models when API is unavailable
     */
    protected function getFallbackModels(): array
    {
        return [
            'meta-llama/llama-3.1-8b-instruct' => [
                'id' => 'meta-llama/llama-3.1-8b-instruct',
                'display_name' => 'LLaMA 3.1 8B Instruct',
                'context_length' => 131072,
                'owned_by' => 'meta'
            ],
            'meta-llama/llama-3.1-70b-instruct' => [
                'id' => 'meta-llama/llama-3.1-70b-instruct',
                'display_name' => 'LLaMA 3.1 70B Instruct',
                'context_length' => 131072,
                'owned_by' => 'meta'
            ],
            'deepseek/deepseek-r1' => [
                'id' => 'deepseek/deepseek-r1',
                'display_name' => 'DeepSeek R1',
                'context_length' => 65536,
                'owned_by' => 'deepseek'
            ],
            'deepseek/deepseek-r1-turbo' => [
                'id' => 'deepseek/deepseek-r1-turbo',
                'display_name' => 'DeepSeek R1 Turbo',
                'context_length' => 65536,
                'owned_by' => 'deepseek'
            ],
            'mistralai/mistral-7b-instruct' => [
                'id' => 'mistralai/mistral-7b-instruct',
                'display_name' => 'Mistral 7B Instruct',
                'context_length' => 32768,
                'owned_by' => 'mistralai'
            ]
        ];
    }

    /**
     * Format model display name for better UX
     */
    protected function formatModelDisplayName(string $modelId): string
    {
        // Convert model ID to human-readable format
        $displayName = str_replace(['/', '-'], [' ', ' '], $modelId);
        $displayName = ucwords($displayName);

        // Handle specific providers
        $displayName = str_replace([
            'Meta Llama',
            'Mistralai',
            'Deepseek'
        ], [
            'LLaMA',
            'Mistral',
            'DeepSeek'
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
            $timeout = (int) config('ai.engines.novita.timeout', 120);

            /*
            \Log::info('connect info',[
                'headers' => $headers,
                'server_url' => $this->serverUrl,
                'data' => $data
            ]);
            */

            $response = $this->http
                ->withHeaders($headers)
                ->timeout($timeout)
                ->post($this->serverUrl, $data);

            if ($response->failed()) {
                $errorBody = $response->json();
                $errorMessage = $errorBody['error']['message'] ?? config('ai.global.error_messages.connection_failed', 'Unknown error');
                $errorCode = $errorBody['error']['code'] ?? 'unknown';
                throw new AiModelException("Novita AI API Error ({$response->status()}, {$errorCode}): $errorMessage");
            }

            $result = $response->json();

            if (!isset($result['choices'][0]['message']['content'])) {
                $this->logger->warning("Invalid Novita AI response format", [
                    'response' => $result,
                    'model' => $this->model
                ]);
                $errorMessage = config('ai.global.error_messages.invalid_format', 'Invalid response format from Novita AI API');
                throw new AiModelException($errorMessage);
            }

            // Log token usage if enabled
            if (isset($result['usage']) && config('ai.engines.novita.log_usage', true)) {
                $this->logger->info("Novita AI tokens used", [
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
            $this->logger->error("NovitaModel error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'context_size' => count($request->getContext()),
                'model' => $this->model
            ]);

            return $this->handleError($e);
        }
    }

    /**
     * Handle different types of Novita AI errors
     */
    protected function handleError(\Exception $e): ModelResponseDTO
    {
        $errorMessages = config('ai.engines.novita.error_messages', []);
        $errorMessage = $e->getMessage();

        $this->logger->error("Novita AI Error Details", [
            'error_message' => $errorMessage,
            'api_key_masked' => substr($this->apiKey, 0, 8) . '...',
            'model' => $this->model,
            'server_url' => $this->serverUrl
        ]);

        // Check for HTTP status codes in error message
        if (preg_match('/Novita AI API Error \((\d+),/', $errorMessage, $matches)) {
            $statusCode = intval($matches[1]);

            switch ($statusCode) {
                case 401:
                    $message = $errorMessages['invalid_api_key'] ?? "Invalid API key. Please check your Novita AI API key.";
                    break;
                case 403:
                    $message = $errorMessages['insufficient_quota'] ?? "Not enough Novita AI quota. Check your account balance.";
                    break;
                case 404:
                    $message = $errorMessages['invalid_model'] ?? "Model '{$this->model}' not found. Please choose a different model.";
                    break;
                case 429:
                    $message = $errorMessages['rate_limit'] ?? "Novita AI request limit exceeded. Please try again later.";
                    break;
                case 500:
                    $message = $errorMessages['model_unavailable'] ?? "Model temporarily unavailable. Please try again later.";
                    break;
                default:
                    $message = $errorMessages['api_error'] ?? "Error from Novita AI API: HTTP {$statusCode}";
            }

            return new ModelResponseDTO("error\n{$message}", true);
        }

        // Check for specific error types
        if (str_contains($errorMessage, 'rate_limit_exceeded')) {
            $message = $errorMessages['rate_limit'] ?? "Novita AI request limit exceeded. Please try again later.";
            return new ModelResponseDTO("error\n{$message}", true);
        }

        if (str_contains($errorMessage, 'insufficient_quota')) {
            $message = $errorMessages['insufficient_quota'] ?? "Not enough Novita AI quota. Check your account balance.";
            return new ModelResponseDTO("error\n{$message}", true);
        }

        if (str_contains($errorMessage, 'Connection refused') || str_contains($errorMessage, 'Could not resolve host')) {
            $message = $errorMessages['connection_failed'] ?? "Failed to connect to Novita AI. Please check your internet connection.";
            return new ModelResponseDTO("error\n{$message}", true);
        }

        if (str_contains($errorMessage, 'API Error')) {
            $message = $errorMessages['api_error'] ?? "Error from Novita AI API: " . $errorMessage;
            return new ModelResponseDTO("error\n{$message}", true);
        }

        $message = $errorMessages['general'] ?? "Error contacting Novita AI: " . $errorMessage;
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
        $baseHeaders = config('ai.engines.novita.request_headers', [
            'Content-Type' => 'application/json',
        ]);

        return array_merge($baseHeaders, [
            'Authorization' => 'Bearer ' . $this->apiKey,
        ]);
    }

    /**
     * Build messages array for Novita AI API
     *
     * @param AiModelRequestInterface $request
     * @return array
     */
    protected function buildMessages(AiModelRequestInterface $request): array
    {
        $systemMessage = $this->prepareMessage($request);
        $messages = [];

        // Novita AI uses system role like OpenAI
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

            // Map roles for Novita AI (OpenAI-compatible)
            switch ($role) {
                case 'user':
                case 'command':
                    $messages[] = [
                        'role' => 'user',
                        'content' => $content
                    ];
                    break;

                case 'thinking':
                case 'speaking':
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
            $errorMessage = config('ai.global.error_messages.empty_response', 'Error: Novita AI did not provide a response.');
            return "response_from_model\n{$errorMessage}";
        }

        $cleanOutput = trim($output);

        // Apply cleanup patterns from config
        $cleanupPattern = config('ai.engines.novita.cleanup.role_prefixes', '/^(Assistant|AI|Bot):\s*/i');
        $cleanOutput = preg_replace($cleanupPattern, '', $cleanOutput);

        if (empty($cleanOutput)) {
            $errorMessage = config('ai.global.error_messages.empty_response', 'Error: Novita AI returned an empty response.');
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
        $modePresets = config('ai.engines.novita.mode_presets', []);

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
     */
    public function getModelInfo(): array
    {
        $availableModels = $this->getAvailableModels();
        return $availableModels[$this->model] ?? [
            'id' => $this->model,
            'display_name' => $this->formatModelDisplayName($this->model),
            'owned_by' => 'novita'
        ];
    }

    /**
     * Check if current model is available
     */
    public function isModelAvailable(): bool
    {
        $availableModels = $this->getAvailableModels();
        return isset($availableModels[$this->model]);
    }

    /**
     * Get models suitable for specific tasks
     */
    public function getModelsByCategory(string $category = 'general'): array
    {
        $availableModels = $this->getAvailableModels();
        $categorizedModels = [];

        foreach ($availableModels as $modelId => $modelInfo) {
            $matches = false;

            switch ($category) {
                case 'reasoning':
                    $matches = str_contains(strtolower($modelId), 'deepseek') ||
                              str_contains(strtolower($modelId), 'qwen');
                    break;
                case 'creative':
                    $matches = str_contains(strtolower($modelId), 'llama') ||
                              str_contains(strtolower($modelId), 'mistral');
                    break;
                case 'coding':
                    $matches = str_contains(strtolower($modelId), 'code') ||
                              str_contains(strtolower($modelId), 'deepseek');
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
}

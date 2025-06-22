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
 * Google Gemini AI engine implementation
 *
 * Supports Gemini 2.0 Flash, Gemini 1.5 Pro, Gemini 1.5 Flash and other Google models
 * Uses OpenAI-compatible API through Google AI Studio
 */
class GeminiModel implements AIModelEngineInterface
{
    use AiModelPromptTrait;

    protected ?string $serverUrl = null;
    protected string $apiKey;
    protected string $model;

    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
        protected CacheManager $cache,
        protected array $config = []
    ) {
        // Get default config from global AI config
        $defaultConfig = config('ai.engines.gemini', []);

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
        return 'gemini';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return config('ai.engines.gemini.display_name', 'Google Gemini');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return config(
            'ai.engines.gemini.description',
            'Google Gemini is a multimodal AI model that can understand and generate text, images, audio, and video. Features advanced reasoning, large context windows (up to 2M tokens), and multimodal capabilities. Access through Google AI Studio.'
        );
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        $models = config('ai.engines.gemini.models', []);
        $validation = config('ai.engines.gemini.validation', []);

        $modelOptions = [];
        foreach ($models as $modelId => $modelInfo) {
            $modelOptions[$modelId] = $modelInfo['display_name'] ?? $modelId;
        }

        return [
            'api_key' => [
                'type' => 'password',
                'label' => 'API Key',
                'description' => 'Get your API key from Google AI Studio: https://aistudio.google.com/app/apikey',
                'placeholder' => 'AIza...',
                'required' => true
            ],
            'model' => [
                'type' => 'text',
                'label' => 'Model name',
                'description' => 'Gemini model identifier (e.g., gemini-2.0-flash, gemini-1.5-pro)',
                'placeholder' => 'gemini-2.0-flash',
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
                'description' => 'Nucleus sampling - limits the selection to top p% probability mass',
                'min' => $validation['top_p']['min'] ?? 0,
                'max' => $validation['top_p']['max'] ?? 1,
                'step' => 0.05,
                'required' => false
            ],
            'top_k' => [
                'type' => 'number',
                'label' => 'Top K',
                'description' => 'Limits the selection to top K most likely tokens',
                'min' => $validation['top_k']['min'] ?? 1,
                'max' => $validation['top_k']['max'] ?? 200,
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
        $configPresets = config('ai.engines.gemini.recommended_presets', []);

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
                'name' => 'Gemini 2.0 Flash - Balanced',
                'description' => 'Latest Gemini 2.0 Flash with balanced settings for general use',
                'config' => [
                    'model' => 'gemini-2.0-flash',
                    'temperature' => 0.8,
                    'top_p' => 0.9,
                    'top_k' => 40,
                    'max_tokens' => 2048,
                ]
            ],
            [
                'name' => 'Gemini 1.5 Pro - Advanced Reasoning',
                'description' => 'Gemini 1.5 Pro optimized for complex reasoning and analysis',
                'config' => [
                    'model' => 'gemini-1.5-pro',
                    'temperature' => 0.7,
                    'top_p' => 0.95,
                    'top_k' => 64,
                    'max_tokens' => 4096,
                ]
            ],
            [
                'name' => 'Gemini 1.5 Flash - Fast & Efficient',
                'description' => 'Gemini 1.5 Flash optimized for speed and efficiency',
                'config' => [
                    'model' => 'gemini-1.5-flash',
                    'temperature' => 0.6,
                    'top_p' => 0.9,
                    'top_k' => 40,
                    'max_tokens' => 1536,
                ]
            ],
            [
                'name' => 'Gemini 2.0 Flash - Creative',
                'description' => 'Creative writing and brainstorming with Gemini 2.0 Flash',
                'config' => [
                    'model' => 'gemini-2.0-flash',
                    'temperature' => 1.2,
                    'top_p' => 0.95,
                    'top_k' => 64,
                    'max_tokens' => 3072,
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
            'model' => config('ai.engines.gemini.model', 'gemini-2.0-flash'),
            'max_tokens' => (int) config('ai.engines.gemini.max_tokens', 2048),
            'temperature' => (float) config('ai.engines.gemini.temperature', 0.8),
            'top_p' => (float) config('ai.engines.gemini.top_p', 0.9),
            'top_k' => (int) config('ai.engines.gemini.top_k', 40),
            'api_key' => config('ai.engines.gemini.api_key', ''),
            'server_url' => config('ai.engines.gemini.server_url', 'https://generativelanguage.googleapis.com/v1beta/chat/completions'),
            'models_endpoint' => config('ai.engines.gemini.models_endpoint', 'https://generativelanguage.googleapis.com/v1beta/models'),
            'system_prompt' => config('ai.engines.gemini.system_prompt', 'You are a useful AI assistant.')
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

        // Validate API key format (basic check)
        if (isset($config['api_key']) && !str_starts_with($config['api_key'], 'AIza')) {
            $errors['api_key'] = 'Invalid Google AI API key format. It should start with "AIza"';
        }

        // Get validation rules from config
        $validation = config('ai.engines.gemini.validation', []);

        // Validate numeric parameters using config ranges
        $numericFields = ['temperature', 'top_p', 'top_k', 'max_tokens'];

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
            $modelsEndpoint = $this->config['models_endpoint'] ?? 'https://generativelanguage.googleapis.com/v1beta/models';
            $headers = $this->getRequestHeaders();
            $timeout = config('ai.engines.gemini.timeout', 10);

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
            $modelsEndpoint = $this->config['models_endpoint'] ?? 'https://generativelanguage.googleapis.com/v1beta/models';
            $headers = $this->getRequestHeaders();
            $timeout = config('ai.engines.gemini.timeout', 10);

            $response = $this->http
                ->withHeaders($headers)
                ->timeout($timeout)
                ->get($modelsEndpoint);

            if ($response->successful()) {
                $models = $response->json();
                return [
                    'success' => true,
                    'message' => 'Successfully connected to Google Gemini API.',
                    'available_models' => $models['models'] ?? [],
                    'total_models' => count($models['models'] ?? [])
                ];
            }

            return [
                'success' => false,
                'message' => 'Google Gemini API responded with an error: ' . $response->status(),
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to connect to Google Gemini API: ' . $e->getMessage(),
                'error_type' => get_class($e)
            ];
        }
    }

    /**
     * Get available models dynamically from Gemini API
     */
    public function getAvailableModels(): array
    {
        try {
            $modelsEndpoint = $this->config['models_endpoint'] ?? 'https://generativelanguage.googleapis.com/v1beta/models';
            $headers = $this->getRequestHeaders();
            $timeout = config('ai.engines.gemini.timeout', 30);

            $response = $this->http
                ->withHeaders($headers)
                ->timeout($timeout)
                ->get($modelsEndpoint);

            if ($response->successful()) {
                $data = $response->json();
                $models = [];

                foreach ($data['models'] ?? [] as $model) {
                    $modelName = $model['name'] ?? '';

                    // Extract model ID from full name (e.g., "models/gemini-2.0-flash" -> "gemini-2.0-flash")
                    $modelId = str_replace('models/', '', $modelName);

                    // Only include chat-compatible models
                    $supportedMethods = $model['supportedGenerationMethods'] ?? [];
                    if (in_array('generateContent', $supportedMethods) || in_array('streamGenerateContent', $supportedMethods)) {
                        $models[$modelId] = [
                            'id' => $modelId,
                            'display_name' => $this->formatModelDisplayName($modelId),
                            'description' => $model['description'] ?? '',
                            'input_token_limit' => $model['inputTokenLimit'] ?? null,
                            'output_token_limit' => $model['outputTokenLimit'] ?? null,
                            'supported_methods' => $supportedMethods,
                            'version' => $model['version'] ?? null
                        ];
                    }
                }

                return $models;
            }

            $this->logger->warning('Failed to fetch Gemini models', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return $this->getFallbackModels();

        } catch (\Exception $e) {
            $this->logger->error('Error fetching Gemini models: ' . $e->getMessage());
            return $this->getFallbackModels();
        }
    }

    /**
     * Get fallback models when API is unavailable
     */
    protected function getFallbackModels(): array
    {
        return [
            'gemini-2.0-flash' => [
                'id' => 'gemini-2.0-flash',
                'display_name' => 'Gemini 2.0 Flash',
                'description' => 'Latest multimodal model with fast performance',
                'input_token_limit' => 1000000,
                'output_token_limit' => 8192,
                'category' => 'general'
            ],
            'gemini-1.5-pro' => [
                'id' => 'gemini-1.5-pro',
                'display_name' => 'Gemini 1.5 Pro',
                'description' => 'Most capable model for complex reasoning tasks',
                'input_token_limit' => 2000000,
                'output_token_limit' => 8192,
                'category' => 'reasoning'
            ],
            'gemini-1.5-flash' => [
                'id' => 'gemini-1.5-flash',
                'display_name' => 'Gemini 1.5 Flash',
                'description' => 'Fast and efficient model for everyday tasks',
                'input_token_limit' => 1000000,
                'output_token_limit' => 8192,
                'category' => 'general'
            ],
            'gemini-1.5-flash-8b' => [
                'id' => 'gemini-1.5-flash-8b',
                'display_name' => 'Gemini 1.5 Flash 8B',
                'description' => 'Compact model optimized for speed and efficiency',
                'input_token_limit' => 1000000,
                'output_token_limit' => 8192,
                'category' => 'general'
            ]
        ];
    }

    /**
     * Format model display name for better UX
     */
    protected function formatModelDisplayName(string $modelId): string
    {
        // Convert model ID to human-readable format
        $displayName = str_replace(['-', '_'], [' ', ' '], $modelId);
        $displayName = ucwords($displayName);

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
                'stream' => false
            ];

            // Add top_k if configured (Gemini-specific parameter)
            if (isset($this->config['top_k'])) {
                $data['top_k'] = (int) $this->config['top_k'];
            }

            $headers = $this->getRequestHeaders();
            $timeout = config('ai.engines.gemini.timeout', 120);

            $response = $this->http
                ->withHeaders($headers)
                ->timeout($timeout)
                ->post($this->serverUrl, $data);

            if ($response->failed()) {
                $errorBody = $response->json();
                $errorMessage = $errorBody['error']['message'] ?? config('ai.global.error_messages.connection_failed', 'Unknown error');
                $errorCode = $errorBody['error']['code'] ?? 'unknown';
                throw new AiModelException("Google Gemini API Error ({$response->status()}, {$errorCode}): $errorMessage");
            }

            $result = $response->json();

            if (!isset($result['choices'][0]['message']['content'])) {
                $this->logger->warning("Invalid Gemini response format", [
                    'response' => $result,
                    'model' => $this->model
                ]);
                $errorMessage = config('ai.global.error_messages.invalid_format', 'Invalid response format from Google Gemini API');
                throw new AiModelException($errorMessage);
            }

            // Log token usage if enabled
            if (isset($result['usage']) && config('ai.engines.gemini.log_usage', true)) {
                $this->logger->info("Gemini tokens used", [
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
            $this->logger->error("GeminiModel error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'context_size' => count($request->getContext()),
                'model' => $this->model
            ]);

            return $this->handleError($e);
        }
    }

    /**
     * Handle different types of Gemini API errors
     */
    protected function handleError(\Exception $e): ModelResponseDTO
    {
        $errorMessages = config('ai.engines.gemini.error_messages', []);

        // Check for specific error types
        if (str_contains($e->getMessage(), 'quota')) {
            $message = $errorMessages['quota_exceeded'] ?? "Gemini API quota exceeded. Check your usage at Google AI Studio.";
            return new ModelResponseDTO("error\n{$message}", true);
        }

        if (str_contains($e->getMessage(), 'PERMISSION_DENIED')) {
            $message = $errorMessages['permission_denied'] ?? "Permission denied. Check your API key permissions.";
            return new ModelResponseDTO("error\n{$message}", true);
        }

        if (str_contains($e->getMessage(), 'INVALID_ARGUMENT')) {
            $message = $errorMessages['invalid_argument'] ?? "Invalid request format or parameters.";
            return new ModelResponseDTO("error\n{$message}", true);
        }

        if (str_contains($e->getMessage(), 'model not found')) {
            $message = $errorMessages['model_not_found'] ?? "Selected model is not available. Please choose a different model.";
            return new ModelResponseDTO("error\n{$message}", true);
        }

        if (str_contains($e->getMessage(), 'API Error')) {
            $message = $errorMessages['api_error'] ?? "Error from Google Gemini API: " . $e->getMessage();
            return new ModelResponseDTO("error\n{$message}", true);
        }

        $message = $errorMessages['general'] ?? "Error contacting Google Gemini: " . $e->getMessage();
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
     * Get request headers for Gemini API
     */
    protected function getRequestHeaders(): array
    {
        $baseHeaders = config('ai.engines.gemini.request_headers', [
            'Content-Type' => 'application/json',
        ]);

        return array_merge($baseHeaders, [
            'Authorization' => 'Bearer ' . $this->apiKey,
        ]);
    }

    /**
     * Build messages array using global continuation patterns
     */
    protected function buildMessages(
        AiModelRequestInterface $request
    ): array {
        $systemMessage = $this->prepareMessage($request);

        $messages = [
            [
                'role' => 'system',
                'content' => $systemMessage
            ]
        ];

        $lastRole = null;
        $continuationMessage = config('ai.global.message_continuation.cycle_continue', '[continue your cycle]');

        $context = $request->getContext();
        foreach ($context as $entry) {
            $role = $entry['role'] ?? 'thinking';
            $content = $entry['content'] ?? '';

            if (empty(trim($content))) {
                continue;
            }

            switch ($role) {
                case 'user':
                case 'command':
                case 'thinking':
                case 'speaking':
                default:
                    // If the previous message was also from assistant, we merge
                    if ($lastRole === 'assistant' && !empty($messages)) {
                        $lastIndex = count($messages) - 1;
                        $messages[$lastIndex]['content'] .= "\n\n" . $content;
                    } else {
                        $messages[] = [
                            'role' => 'assistant',
                            'content' => $content
                        ];
                    }
                    $lastRole = 'assistant';
                    break;
            }
        }

        if ($lastRole === 'assistant') {
            $messages[] = [
                'role' => 'user',
                'content' => $continuationMessage
            ];
        }

        return $messages;
    }

    /**
     * Clean output using config patterns
     */
    protected function cleanOutput(?string $output): string
    {
        if (empty($output)) {
            $errorMessage = config('ai.global.error_messages.empty_response', 'Error: Google Gemini did not provide a response.');
            return "response_from_model\n{$errorMessage}";
        }

        $cleanOutput = trim($output);

        // Apply cleanup patterns from config
        $cleanupPattern = config('ai.engines.gemini.cleanup.role_prefixes', '/^(Assistant|AI|Gemini|Google):\s*/i');
        $cleanOutput = preg_replace($cleanupPattern, '', $cleanOutput);

        if (empty($cleanOutput)) {
            $errorMessage = config('ai.global.error_messages.empty_response', 'Error: Google Gemini returned an empty response.');
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
        $modePresets = config('ai.engines.gemini.mode_presets', []);

        if (isset($modePresets[$mode])) {
            $this->config = array_merge($this->config, $modePresets[$mode]);
            return;
        }

        // Fallback to hardcoded presets
        switch ($mode) {
            case 'creative':
                $this->config = array_merge($this->config, [
                    'temperature' => 1.2,
                    'top_p' => 0.95,
                    'top_k' => 64,
                    'max_tokens' => 4096
                ]);
                break;

            case 'focused':
                $this->config = array_merge($this->config, [
                    'temperature' => 0.3,
                    'top_p' => 0.8,
                    'top_k' => 20,
                    'max_tokens' => 2048
                ]);
                break;

            case 'balanced':
            default:
                $this->config = array_merge($this->config, [
                    'temperature' => 0.8,
                    'top_p' => 0.9,
                    'top_k' => 40,
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
            'input' => $modelInfo['input_token_limit'] ?? 1000000,
            'output' => $modelInfo['output_token_limit'] ?? 8192
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
            'description' => 'Google Gemini model'
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
                    $matches = str_contains(strtolower($modelId), 'pro') ||
                              str_contains(strtolower($modelId), '2.0');
                    break;
                case 'fast':
                    $matches = str_contains(strtolower($modelId), 'flash') ||
                              str_contains(strtolower($modelId), '8b');
                    break;
                case 'multimodal':
                    $matches = str_contains(strtolower($modelId), 'gemini'); // All Gemini models are multimodal
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

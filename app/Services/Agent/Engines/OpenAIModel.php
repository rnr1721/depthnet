<?php

namespace App\Services\Agent\Engines;

use App\Contracts\Agent\AiModelResponseInterface;
use App\Contracts\Agent\Models\AIModelEngineInterface;
use App\Exceptions\AiModelException;
use App\Services\Agent\DTO\ModelResponseDTO;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI GPT engine implementation
 *
 * Supports GPT-4, GPT-4o, GPT-3.5-turbo and o1 models via OpenAI API
 */
class OpenAIModel implements AIModelEngineInterface
{
    use AiModelTrait;

    protected string $apiKey;
    protected string $model;

    public function __construct(
        protected HttpFactory $http,
        protected ?string $serverUrl = null,
        protected array $config = []
    ) {
        // Get default config from global AI config
        $defaultConfig = config('ai.engines.openai', []);

        // Merge with provided config
        $this->config = array_merge($this->getDefaultConfig(), $defaultConfig, $config);

        // Set server URL from config if not provided
        $this->serverUrl = $serverUrl ?? $this->config['server_url'];

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
            'max_tokens' => config('ai.engines.openai.max_tokens', 4096),
            'temperature' => config('ai.engines.openai.temperature', 0.8),
            'top_p' => config('ai.engines.openai.top_p', 0.9),
            'frequency_penalty' => config('ai.engines.openai.frequency_penalty', 0.0),
            'presence_penalty' => config('ai.engines.openai.presence_penalty', 0.0),
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
        array $context,
        string $initialMessage,
        string $notepadContent = '',
        int $currentDophamineLevel = 5,
        string $commandInstructions = ''
    ): AiModelResponseInterface {
        try {
            $messages = $this->buildMessages(
                $context,
                $initialMessage,
                $notepadContent,
                $currentDophamineLevel,
                $commandInstructions
            );

            $data = [
                'model' => $this->model,
                'messages' => $messages,
                'max_tokens' => $this->config['max_tokens'],
                'temperature' => $this->config['temperature'],
                'top_p' => $this->config['top_p'],
                'frequency_penalty' => $this->config['frequency_penalty'],
                'presence_penalty' => $this->config['presence_penalty'],
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
                Log::warning("Invalid OpenAI response format", ['response' => $result]);
                $errorMessage = config('ai.global.error_messages.invalid_format', 'Invalid response format from OpenAI API');
                throw new AiModelException($errorMessage);
            }

            // Log token usage if enabled
            if (isset($result['usage']) && config('ai.engines.openai.log_usage', true)) {
                Log::info("OpenAI tokens used", [
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
            Log::error("OpenAIModel error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'context_size' => count($context),
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
     * Build messages array using global continuation patterns
     */
    protected function buildMessages(
        array $context,
        string $initialMessage,
        string $notepadContent,
        int $currentDophamineLevel,
        string $commandInstructions
    ): array {
        $systemMessage = $this->prepareMessage(
            $initialMessage,
            $notepadContent,
            $currentDophamineLevel,
            $commandInstructions
        );

        $messages = [
            [
                'role' => 'system',
                'content' => $systemMessage
            ]
        ];

        $lastRole = null;
        $continuationMessage = config('ai.global.message_continuation.cycle_continue', '[continue your cycle]');

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
            Log::warning("Failed to check OpenAI model availability: " . $e->getMessage());
            return false;
        }
    }
}

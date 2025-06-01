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
        protected string $serverUrl = "https://api.openai.com/v1/chat/completions",
        protected array $config = []
    ) {
        // Merge with defaults first
        $this->config = array_merge($this->getDefaultConfig(), $config);

        $this->apiKey = $config['api_key'] ?? '';
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
        return 'OpenAI GPT';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'OpenAI GPT модели - самые популярные и мощные языковые модели. Включает GPT-4o, GPT-4 Turbo, GPT-3.5 Turbo и модели рассуждений o1. Отличается высоким качеством и надежностью.';
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
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
                'options' => [
                    'gpt-4o' => 'GPT-4o (newest and fastest)',
                    'gpt-4o-mini' => 'GPT-4o Mini (fast and economical)',
                    'gpt-4-turbo' => 'GPT-4 Turbo (powerful, big context)',
                    'gpt-4' => 'GPT-4 (original powerful model)',
                    'gpt-3.5-turbo' => 'GPT-3.5 Turbo (fast and economical)',
                    'o1-preview' => 'o1 Preview (reasoning, slow)',
                    'o1-mini' => 'o1 Mini (reasoning, faster)'
                ],
                'required' => true
            ],
            'temperature' => [
                'type' => 'number',
                'label' => 'Temperature',
                'description' => 'Controls randomness of responses (0 = deterministic, 2 = very creative)',
                'min' => 0,
                'max' => 2,
                'step' => 0.1,
                'required' => false
            ],
            'top_p' => [
                'type' => 'number',
                'label' => 'Top P',
                'description' => 'Nucleus sampling - temperature alternative',
                'min' => 0,
                'max' => 1,
                'step' => 0.05,
                'required' => false
            ],
            'max_tokens' => [
                'type' => 'number',
                'label' => 'Max tokens',
                'description' => 'Maximum number of tokens in response',
                'min' => 1,
                'max' => 128000,
                'required' => false
            ],
            'frequency_penalty' => [
                'type' => 'number',
                'label' => 'Штраф за частоту',
                'description' => 'Reduces the likelihood of repeating frequently used tokens',
                'min' => -2,
                'max' => 2,
                'step' => 0.1,
                'required' => false
            ],
            'presence_penalty' => [
                'type' => 'number',
                'label' => 'Fine for presence',
                'description' => 'Reduces the likelihood of repeating already used tokens',
                'min' => -2,
                'max' => 2,
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
        ];
    }

    /**
     * @inheritDoc
     */
    public function getDefaultConfig(): array
    {
        return [
            'model' => 'gpt-4o',
            'max_tokens' => 4096,
            'temperature' => 0.8,
            'top_p' => 0.9,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
            'api_key' => '',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'system_prompt' => 'You are a useful AI assistant. Answer in Russian.'
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

        // Validate model
        $supportedModels = [
            'gpt-4o',
            'gpt-4o-mini',
            'gpt-4-turbo',
            'gpt-4',
            'gpt-3.5-turbo',
            'o1-preview',
            'o1-mini'
        ];

        if (isset($config['model']) && !in_array($config['model'], $supportedModels)) {
            $errors['model'] = 'Unsupported model: ' . $config['model'] . '. Supported: ' . implode(', ', $supportedModels);
        }

        // Validate temperature
        if (isset($config['temperature'])) {
            $temp = (float) $config['temperature'];
            if ($temp < 0 || $temp > 2) {
                $errors['temperature'] = 'Temperature should be between 0 and 2';
            }
        }

        // Validate top_p
        if (isset($config['top_p'])) {
            $topP = (float) $config['top_p'];
            if ($topP < 0 || $topP > 1) {
                $errors['top_p'] = 'Top P must be between 0 and 1';
            }
        }

        // Validate frequency_penalty
        if (isset($config['frequency_penalty'])) {
            $penalty = (float) $config['frequency_penalty'];
            if ($penalty < -2 || $penalty > 2) {
                $errors['frequency_penalty'] = 'Frequency penalty should be between -2 and 2';
            }
        }

        // Validate presence_penalty
        if (isset($config['presence_penalty'])) {
            $penalty = (float) $config['presence_penalty'];
            if ($penalty < -2 || $penalty > 2) {
                $errors['presence_penalty'] = 'The penalty for presence should be from -2 to 2';
            }
        }

        // Validate max_tokens
        if (isset($config['max_tokens'])) {
            $maxTokens = (int) $config['max_tokens'];
            if ($maxTokens < 1 || $maxTokens > 128000) {
                $errors['max_tokens'] = 'The maximum tokens must be from 1 to 128000';
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
            // Test with a minimal request
            $data = [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'user', 'content' => 'Hi']
                ],
                'max_tokens' => 5
            ];

            $response = $this->http
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ])
                ->timeout(10)
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

            $response = $this->http
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ])
                ->timeout(120)
                ->post($this->serverUrl, $data);

            if ($response->failed()) {
                $errorBody = $response->json();
                $errorMessage = $errorBody['error']['message'] ?? 'Unknown error';
                $errorCode = $errorBody['error']['code'] ?? 'unknown';
                throw new AiModelException("OpenAI API Error ({$response->status()}, {$errorCode}): $errorMessage");
            }

            $result = $response->json();

            if (!isset($result['choices'][0]['message']['content'])) {
                Log::warning("Invalid OpenAI response format", ['response' => $result]);
                throw new AiModelException("Invalid response format from OpenAI API");
            }

            // Logging token usage
            if (isset($result['usage'])) {
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

            // OpenAI's dedicated error handling
            if (str_contains($e->getMessage(), 'rate_limit_exceeded')) {
                return new ModelResponseDTO(
                    "error\nOpenAI request limit exceeded. Please try again later.",
                    true
                );
            }

            if (str_contains($e->getMessage(), 'insufficient_quota')) {
                return new ModelResponseDTO(
                    "error\nNot enough OpenAI quota. Check your account balance.",
                    true
                );
            }

            if (str_contains($e->getMessage(), 'API Error')) {
                return new ModelResponseDTO(
                    "error\nError from OpenAI API: " . $e->getMessage(),
                    true
                );
            }

            return new ModelResponseDTO(
                "error\nError contacting OpenAI: " . $e->getMessage(),
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
     * Build messages array for OpenAI API
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
                'content' => '[continue your cycle]'
            ];
        }

        return $messages;
    }

    /**
     * Clean output from OpenAI
     */
    protected function cleanOutput(?string $output): string
    {
        if (empty($output)) {
            return "response_from_model\nError: OpenAI did not provide a response.";
        }

        $cleanOutput = trim($output);
        $cleanOutput = preg_replace('/^(Assistant|AI|GPT):\s*/i', '', $cleanOutput);

        if (empty($cleanOutput)) {
            return "response_from_model\nError: OpenAI returned an empty response.";
        }

        return $cleanOutput;
    }

    /**
     * Set optimized settings for different modes
     */
    public function setMode(string $mode): void
    {
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
     * Get information about model limits
     */
    public function getModelLimits(): array
    {
        $limits = [
            'gpt-4o' => ['input' => 128000, 'output' => 16384],
            'gpt-4o-mini' => ['input' => 128000, 'output' => 16384],
            'gpt-4-turbo' => ['input' => 128000, 'output' => 4096],
            'gpt-4' => ['input' => 8192, 'output' => 4096],
            'gpt-3.5-turbo' => ['input' => 16385, 'output' => 4096],
            'o1-preview' => ['input' => 128000, 'output' => 32768],
            'o1-mini' => ['input' => 128000, 'output' => 65536]
        ];

        return $limits[$this->model] ?? ['input' => 16385, 'output' => 4096];
    }

    /**
     * Get the cost of using the model (in dollars per 1K tokens)
     */
    public function getModelPricing(): array
    {
        $pricing = [
            'gpt-4o' => ['input' => 0.0025, 'output' => 0.01],
            'gpt-4o-mini' => ['input' => 0.00015, 'output' => 0.0006],
            'gpt-4-turbo' => ['input' => 0.01, 'output' => 0.03],
            'gpt-4' => ['input' => 0.03, 'output' => 0.06],
            'gpt-3.5-turbo' => ['input' => 0.0005, 'output' => 0.0015],
            'o1-preview' => ['input' => 0.015, 'output' => 0.06],
            'o1-mini' => ['input' => 0.003, 'output' => 0.012]
        ];

        return $pricing[$this->model] ?? ['input' => 0.001, 'output' => 0.002];
    }

    /**
     * Check model availability
     */
    public function isModelAvailable(): bool
    {
        try {
            $response = $this->http
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ])
                ->timeout(10)
                ->get('https://api.openai.com/v1/models');

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

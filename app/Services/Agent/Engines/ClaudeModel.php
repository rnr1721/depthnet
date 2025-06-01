<?php

namespace App\Services\Agent\Engines;

use App\Contracts\Agent\AiModelResponseInterface;
use App\Contracts\Agent\Models\AIModelEngineInterface;
use App\Exceptions\AiModelException;
use App\Services\Agent\DTO\ModelResponseDTO;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Claude AI engine implementation
 *
 * Supports Claude 3 family models via Anthropic API
 */
class ClaudeModel implements AIModelEngineInterface
{
    use AiModelTrait;

    protected string $apiKey;
    protected string $model;

    public function __construct(
        protected HttpFactory $http,
        protected string $serverUrl = "https://api.anthropic.com/v1/messages",
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
        return 'claude';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return 'Claude (Anthropic)';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Claude AI by Anthropic is a powerful language model with excellent analysis, creativity, and security capabilities. Supports the Claude 3 Haiku, Sonnet, and Opus models.';
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
                'description' => 'API key from Anthropic Console',
                'placeholder' => 'sk-ant-api03-...',
                'required' => true
            ],
            'model' => [
                'type' => 'select',
                'label' => 'Model Claude',
                'description' => 'Select the Claude model to use',
                'options' => [
                    'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet (newest)',
                    'claude-3-opus-20240229' => 'Claude 3 Opus (the most powerful)',
                    'claude-3-sonnet-20240229' => 'Claude 3 Sonnet (balance)',
                    'claude-3-haiku-20240307' => 'Claude 3 Haiku (fast and economical)',
                ],
                'required' => true
            ],
            'temperature' => [
                'type' => 'number',
                'label' => 'Temperature',
                'description' => 'Controls the randomness of responses (0 = deterministic, 1 = creative)',
                'min' => 0,
                'max' => 1,
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
                'max' => 8192,
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
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 4096,
            'temperature' => 0.8,
            'top_p' => 0.9,
            'api_key' => '',
            'server_url' => 'https://api.anthropic.com/v1/messages',
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
            'claude-3-5-sonnet-20241022',
            'claude-3-opus-20240229',
            'claude-3-sonnet-20240229',
            'claude-3-haiku-20240307'
        ];

        if (isset($config['model']) && !in_array($config['model'], $supportedModels)) {
            $errors['model'] = 'Unsupported model: ' . $config['model'] . '. Supported: ' . implode(', ', $supportedModels);
        }

        // Validate temperature
        if (isset($config['temperature'])) {
            $temp = (float) $config['temperature'];
            if ($temp < 0 || $temp > 1) {
                $errors['temperature'] = 'Temperature must be between 0 and 1';
            }
        }

        // Validate top_p
        if (isset($config['top_p'])) {
            $topP = (float) $config['top_p'];
            if ($topP < 0 || $topP > 1) {
                $errors['top_p'] = 'Top P must be between 0 and 1';
            }
        }

        // Validate max_tokens
        if (isset($config['max_tokens'])) {
            $maxTokens = (int) $config['max_tokens'];
            if ($maxTokens < 1 || $maxTokens > 8192) {
                $errors['max_tokens'] = 'The maximum number of tokens must be between 1 and 8192';
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
            // Test with a minimal request
            $data = [
                'model' => $this->model,
                'max_tokens' => 10,
                'messages' => [
                    ['role' => 'user', 'content' => 'Hi']
                ]
            ];

            $response = $this->http
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
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
                'max_tokens' => $this->config['max_tokens'],
                'temperature' => $this->config['temperature'],
                'top_p' => $this->config['top_p'],
                'messages' => $messages
            ];

            $response = $this->http
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                ])
                ->timeout(120)
                ->post($this->serverUrl, $data);

            if ($response->failed()) {
                $errorBody = $response->json();
                $errorMessage = $errorBody['error']['message'] ?? 'Unknown error';
                throw new AiModelException("Claude API Error ({$response->status()}): $errorMessage");
            }

            $result = $response->json();

            if (!isset($result['content'][0]['text'])) {
                Log::warning("Invalid Claude response format", ['response' => $result]);
                throw new AiModelException("Invalid response format from Claude API");
            }

            return new ModelResponseDTO(
                $this->cleanOutput($result['content'][0]['text'])
            );
        } catch (\Exception $e) {
            Log::error("ClaudeModel error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'context_size' => count($context),
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
     * Build messages array for Claude API
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

        // Claude API requires a separate system message
        $messages = [];

        // Add system as first user message (Claude API feature)
        $messages[] = [
            'role' => 'user',
            'content' => "SYSTEM INSTRUCTIONS:\n$systemMessage\n\n[Start your first cycle]"
        ];

        $assistantContent = [];

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
                    $assistantContent[] = $content;
                    break;
            }
        }

        if (!empty($assistantContent)) {
            $messages[] = [
                'role' => 'assistant',
                'content' => implode("\n\n", $assistantContent)
            ];
        }

        if (end($messages)['role'] === 'assistant') {
            $messages[] = [
                'role' => 'user',
                'content' => '[continue your cycle]'
            ];
        }

        return $messages;
    }

    /**
     * Clean output from Claude
     */
    protected function cleanOutput(?string $output): string
    {
        if (empty($output)) {
            return "response_from_model\nError: Claude did not provide an answer.";
        }

        $cleanOutput = trim($output);
        $cleanOutput = preg_replace('/^(Assistant|Claude):\s*/i', '', $cleanOutput);

        if (empty($cleanOutput)) {
            return "response_from_model\nError: Claude returned an empty response.";
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
}

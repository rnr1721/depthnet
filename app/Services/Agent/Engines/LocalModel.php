<?php

namespace App\Services\Agent\Engines;

use App\Contracts\Agent\AiModelResponseInterface;
use App\Contracts\Agent\Models\AIModelEngineInterface;
use App\Exceptions\AiModelException;
use App\Services\Agent\DTO\ModelResponseDTO;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Universal engine for local AI models (LLaMA, Phi, Mistral, etc.)
 * Works with OpenAI-compatible API servers like Ollama, LM Studio, text-generation-webui
 */
class LocalModel implements AIModelEngineInterface
{
    use AiModelTrait;

    public function __construct(
        protected HttpFactory $http,
        protected string $serverUrl = "http://localhost:11434",
        protected array $config = []
    ) {
        // Merge with defaults
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'local';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return 'Local models';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Universal engine for local AI models (LLaMA, Phi, Mistral, Code Llama, etc.). Works with OpenAI-compatible servers: Ollama, LM Studio, text-generation-webui, KoboldCPP.';
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'server_url' => [
                'type' => 'url',
                'label' => 'Server URL',
                'description' => 'Local server address with model',
                'placeholder' => 'http://localhost:11434',
                'required' => true
            ],
            'server_type' => [
                'type' => 'select',
                'label' => 'Server type',
                'description' => 'Program for running local models',
                'options' => [
                    'ollama' => 'Ollama (recommended)',
                    'lmstudio' => 'LM Studio',
                    'textgen' => 'Text Generation WebUI',
                    'koboldcpp' => 'KoboldCPP',
                    'openai-compatible' => 'Another OpenAI-compatible server'
                ],
                'required' => false
            ],
            'model' => [
                'type' => 'text',
                'label' => 'Model name',
                'description' => 'The name of the model on the server (as shown in ollama list or similar command)',
                'placeholder' => 'llama3, phi3, mistral, codellama, etc.',
                'required' => true
            ],
            'model_family' => [
                'type' => 'select',
                'label' => 'Model family',
                'description' => 'Model family for proper token handling',
                'options' => [
                    'llama' => 'LLaMA (Meta)',
                    'phi' => 'Phi (Microsoft)',
                    'mistral' => 'Mistral AI',
                    'gemma' => 'Gemma (Google)',
                    'qwen' => 'Qwen (Alibaba)',
                    'codellama' => 'Code Llama',
                    'other' => 'Other/Unknown'
                ],
                'required' => false
            ],
            'temperature' => [
                'type' => 'number',
                'label' => 'Temperature',
                'description' => 'Creativity of responses (0 = deterministic, 2 = very creative)',
                'min' => 0,
                'max' => 2,
                'step' => 0.1,
                'required' => false
            ],
            'max_tokens' => [
                'type' => 'number',
                'label' => 'Max tokens',
                'description' => 'Maximum number of tokens in response',
                'min' => 1,
                'max' => 32768,
                'required' => false
            ],
            'top_p' => [
                'type' => 'number',
                'label' => 'Top P (nucleus sampling)',
                'description' => 'Alternative to temperature for randomness control',
                'min' => 0,
                'max' => 1,
                'step' => 0.05,
                'required' => false
            ],
            'top_k' => [
                'type' => 'number',
                'label' => 'Top K',
                'description' => 'Number of most likely tokens to select',
                'min' => 1,
                'max' => 200,
                'required' => false
            ],
            'repeat_penalty' => [
                'type' => 'number',
                'label' => 'Penalty for repetition',
                'description' => 'Penalty for token repetitions (>1 = less repetitions)',
                'min' => 0.1,
                'max' => 2.0,
                'step' => 0.05,
                'required' => false
            ],
            'timeout' => [
                'type' => 'number',
                'label' => 'Timeout (seconds)',
                'description' => 'Maximum time to wait for a response from the server',
                'min' => 5,
                'max' => 600,
                'required' => false
            ],
            'cleanup_enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Output Clearing',
                'description' => 'Remove service tokens and formatting from model response',
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
                'name' => 'Creative mode',
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
                'name' => 'Accurate analysis',
                'description' => 'Low creativity parameters for factual answers',
                'config' => [
                    'temperature' => 0.3,
                    'top_p' => 0.8,
                    'top_k' => 20,
                    'repeat_penalty' => 1.15,
                    'max_tokens' => 1500
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
            'model' => 'llama3',
            'model_family' => 'llama',
            'server_type' => 'ollama',
            'temperature' => 0.8,
            'max_tokens' => 2048,
            'top_p' => 0.9,
            'top_k' => 40,
            'repeat_penalty' => 1.1,
            'timeout' => 60,
            'cleanup_enabled' => true,
            'server_url' => 'http://localhost:11434',
            'system_prompt' => 'You are a useful AI assistant. Answer in Russian.'
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        // Validate model name
        if (empty($config['model'])) {
            $errors['model'] = 'Model name is required';
        }

        // Validate server_url
        if (isset($config['server_url'])) {
            if (!filter_var($config['server_url'], FILTER_VALIDATE_URL)) {
                $errors['server_url'] = 'The server URL must be a valid URL.';
            }
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

        // Validate top_k
        if (isset($config['top_k'])) {
            $topK = (int) $config['top_k'];
            if ($topK < 1 || $topK > 200) {
                $errors['top_k'] = 'Top K must be between 1 and 200';
            }
        }

        // Validate repeat_penalty
        if (isset($config['repeat_penalty'])) {
            $penalty = (float) $config['repeat_penalty'];
            if ($penalty < 0.1 || $penalty > 2.0) {
                $errors['repeat_penalty'] = 'The penalty for a repeat should be from 0.1 to 2.0';
            }
        }

        // Validate max_tokens
        if (isset($config['max_tokens'])) {
            $maxTokens = (int) $config['max_tokens'];
            if ($maxTokens < 1 || $maxTokens > 32768) {
                $errors['max_tokens'] = 'The maximum number of tokens must be between 1 and 32768';
            }
        }

        // Validate timeout
        if (isset($config['timeout'])) {
            $timeout = (int) $config['timeout'];
            if ($timeout < 5 || $timeout > 600) {
                $errors['timeout'] = 'Timeout should be between 5 and 600 seconds';
            }
        }

        // Validate model_family
        if (isset($config['model_family'])) {
            $supportedFamilies = ['llama', 'phi', 'mistral', 'gemma', 'qwen', 'codellama', 'other'];
            if (!in_array($config['model_family'], $supportedFamilies)) {
                $errors['model_family'] = 'Unsupported model family. Supported: ' . implode(', ', $supportedFamilies);
            }
        }

        // Validate server_type
        if (isset($config['server_type'])) {
            $supportedServers = ['ollama', 'lmstudio', 'textgen', 'koboldcpp', 'openai-compatible'];
            if (!in_array($config['server_type'], $supportedServers)) {
                $errors['server_type'] = 'Unsupported server type. Supported: ' . implode(', ', $supportedServers);
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
            $response = $this->http
                ->timeout(10)
                ->get($this->serverUrl . '/v1/models');

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
            $response = $this->http
                ->timeout(10)
                ->get($this->serverUrl . '/v1/models');

            if ($response->successful()) {
                $models = $response->json();
                return [
                    'success' => true,
                    'message' => 'The connection was established successfully.',
                    'available_models' => $models['data'] ?? [],
                    'server_type' => $this->config['server_type'] ?? 'unknown'
                ];
            }

            return [
                'success' => false,
                'message' => 'The server responded with an error: ' . $response->status(),
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to connect: ' . $e->getMessage(),
                'error_type' => get_class($e)
            ];
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

            $data = array_merge($this->config, [
                'messages' => $messages,
                'stream' => false
            ]);

            // Remove non-API parameters
            unset($data['model_family'], $data['server_type'], $data['cleanup_enabled'], $data['system_prompt']);

            $endpoint = $this->getApiEndpoint();
            $timeout = $this->config['timeout'] ?? 60;

            $response = $this->http
                ->timeout($timeout)
                ->post($this->serverUrl . $endpoint, $data);

            if ($response->failed()) {
                throw new AiModelException("HTTP Error: " . $response->status() . " - " . $response->body());
            }

            $result = $response->json();

            if (!isset($result['choices'][0]['message']['content'])) {
                Log::warning("Invalid local model response format", [
                    'response' => $result,
                    'model' => $this->config['model'] ?? 'unknown',
                    'server_url' => $this->serverUrl
                ]);
                throw new AiModelException("Invalid response format from local model");
            }

            $content = $result['choices'][0]['message']['content'] ?? '';

            return new ModelResponseDTO(
                ($this->config['cleanup_enabled'] ?? true) ? $this->cleanOutput($content) : $content
            );
        } catch (\Exception $e) {
            Log::error("LocalModel error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'context_size' => count($context),
                'config' => $this->config,
                'server_url' => $this->serverUrl
            ]);

            return new ModelResponseDTO(
                "error\nError generating response: " . $e->getMessage(),
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
    }

    /**
     * @inheritDoc
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    // Остальные методы без изменений...

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
            ['role' => 'system', 'content' => $systemMessage]
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
                    // If the previous message was also from assistant
                    if ($lastRole === 'assistant') {
                        $messages[] = [
                            'role' => 'user',
                            'content' => '[please resume thinking]'
                        ];
                    }

                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $content
                    ];
                    break;
            }

            $lastRole = $role === 'user' ? 'user' : 'assistant';
        }

        // At the very end, if the last message is from the assistant, we add a continuation
        if ($lastRole === 'assistant') {
            $messages[] = [
                'role' => 'user',
                'content' => '[please resume thinking]'
            ];
        }

        return $messages;
    }

    protected function cleanOutput(?string $output): string
    {
        if (empty($output)) {
            return "response_from_model\nError: Local model did not respond. Check your server settings.";
        }

        $cleanOutput = $output;

        // Remove ANSI escape sequences
        $cleanOutput = preg_replace('/\x1b\[[0-9;]*m/', '', $cleanOutput);

        // Remove common model-specific tokens based on family
        $modelFamily = $this->config['model_family'] ?? 'other';

        switch ($modelFamily) {
            case 'llama':
                $cleanOutput = preg_replace('/<\|end\|>|<\|eot_id\|>|<\|start_header_id\|>.*?<\|end_header_id\|>/', '', $cleanOutput);
                break;

            case 'phi':
                $cleanOutput = preg_replace('/<\|end\|>|<\|user\|>|<\|assistant\|>|<\|system\|>/', '', $cleanOutput);
                break;

            case 'mistral':
                $cleanOutput = preg_replace('/\[INST\].*?\[\/INST\]|\<s\>|\<\/s\>/', '', $cleanOutput);
                break;
        }

        // Remove role prefixes
        $cleanOutput = preg_replace('/^(assistant|user|system|AI):\s*/i', '', $cleanOutput);

        $cleanOutput = trim($cleanOutput);

        if (empty($cleanOutput)) {
            return "response_from_model\nError: Local model returned an empty response.";
        }

        return $cleanOutput;
    }

    /**
     * Get API endpoint based on server type
     */
    protected function getApiEndpoint(): string
    {
        return match($this->config['server_type']) {
            'ollama' => '/v1/chat/completions',
            'lmstudio' => '/v1/chat/completions',
            'textgen' => '/v1/chat/completions',
            'koboldcpp' => '/v1/chat/completions',
            'openai-compatible' => '/v1/chat/completions',
            default => '/v1/chat/completions'
        };
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
                    'repeat_penalty' => 1.05,
                    'top_k' => 50
                ]);
                break;

            case 'focused':
                $this->config = array_merge($this->config, [
                    'temperature' => 0.3,
                    'top_p' => 0.8,
                    'repeat_penalty' => 1.15,
                    'top_k' => 20
                ]);
                break;

            case 'fast':
                $this->config = array_merge($this->config, [
                    'temperature' => 0.7,
                    'max_tokens' => 1024,
                    'timeout' => 30
                ]);
                break;

            case 'balanced':
            default:
                $this->config = array_merge($this->config, [
                    'temperature' => 0.8,
                    'top_p' => 0.9,
                    'repeat_penalty' => 1.1,
                    'top_k' => 40
                ]);
                break;
        }
    }
}

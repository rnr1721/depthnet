<?php

namespace App\Services\Agent\Models;

use App\Contracts\Agent\AIModelInterface;
use App\Exceptions\AiModelException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

class OpenAIModel implements AIModelInterface
{
    use AiModelTrait;

    protected string $apiKey;
    protected string $model;

    public function __construct(
        protected HttpFactory $http,
        protected string $serverUrl = "https://api.openai.com/v1/chat/completions",
        protected array $config = []
    ) {
        $this->apiKey = $config['api_key'];
        $this->model = $config['model'] ?? 'gpt-4o';

        // Default settings for OpenAI
        $this->config = array_merge([
            'max_tokens' => 4096,
            'temperature' => 0.8,
            'top_p' => 0.9,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
        ], $this->config);
    }

    public function generate(
        array $context,
        string $initialMessage,
        string $notepadContent = '',
        int $currentDophamineLevel = 5,
        string $commandInstructions = ''
    ): string {
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

            return $this->cleanOutput($result['choices'][0]['message']['content']);

        } catch (\Exception $e) {
            Log::error("OpenAIModel error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'context_size' => count($context),
                'model' => $this->model
            ]);

            // OpenAI's dedicated error handling
            if (str_contains($e->getMessage(), 'rate_limit_exceeded')) {
                return "error\nOpenAI request limit exceeded. Please try again later.";
            }

            if (str_contains($e->getMessage(), 'insufficient_quota')) {
                return "error\nNot enough OpenAI quota. Check your account balance.";
            }

            if (str_contains($e->getMessage(), 'API Error')) {
                return "error\nOpenAI API error: " . $e->getMessage();
            }

            return "error\nError contacting OpenAI: " . $e->getMessage();
        }
    }

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

    public function getName(): string
    {
        return 'openai';
    }

    /**
     * Update model settings
     *
     * @param array $newConfig
     * @return void
     */
    public function updateConfig(array $newConfig): void
    {
        $this->config = array_merge($this->config, $newConfig);

        if (isset($newConfig['model'])) {
            $this->model = $newConfig['model'];
        }
    }

    /**
     * Get current settings
     *
     * @return array
     */
    public function getConfig(): array
    {
        return array_merge($this->config, [
            'model' => $this->model,
            'api_key' => substr($this->apiKey, 0, 8) . '...'
        ]);
    }

    /**
     * Operating modes for different tasks
     *
     * @param string $mode
     * @return void
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
     * Supported OpenAI Models
     *
     * @return array
     */
    public static function getSupportedModels(): array
    {
        return [
            'gpt-4o' => 'GPT-4o (newest and fastest)',
            'gpt-4o-mini' => 'GPT-4o Mini (fast and cheap)',
            'gpt-4-turbo' => 'GPT-4 Turbo (powerful, large context)',
            'gpt-4' => 'GPT-4 (original powerful model)',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo (fast and economical)',
            'o1-preview' => 'o1 Preview (reasoning, slow)',
            'o1-mini' => 'o1 Mini (reasoning, faster)'
        ];
    }

    /**
     * Get information about model limits
     *
     * @return array
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
     *
     * @return array
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
     *
     * @return boolean
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

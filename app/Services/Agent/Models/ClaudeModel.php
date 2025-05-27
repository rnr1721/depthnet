<?php

namespace App\Services\Agent\Models;

use App\Contracts\Agent\AIModelInterface;
use App\Exceptions\AiModelException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

class ClaudeModel implements AIModelInterface
{
    use AiModelTrait;

    protected string $apiKey;
    protected string $model;

    public function __construct(
        protected HttpFactory $http,
        protected string $serverUrl = "https://api.anthropic.com/v1/messages",
        protected array $config = []
    ) {
        $this->apiKey = $config['api_key'];
        $this->model = $config['model'] ?? 'claude-3-5-sonnet-20241022';

        // Default Claude settings
        $this->config = array_merge([
            'max_tokens' => 4096,
            'temperature' => 0.8,
            'top_p' => 0.9,
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

            return $this->cleanOutput($result['content'][0]['text']);

        } catch (\Exception $e) {
            Log::error("ClaudeModel error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'context_size' => count($context),
                'model' => $this->model
            ]);

            if (str_contains($e->getMessage(), 'API Error')) {
                return "error\nClaude API ошибка: " . $e->getMessage();
            }

            return "error\nError on Claude request: " . $e->getMessage();
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

    public function getName(): string
    {
        return 'claude-' . str_replace('claude-', '', $this->model);
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

        // Update the model if passed
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

    /**
     * Supported Claude models
     */
    public static function getSupportedModels(): array
    {
        return [
            'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet (newest)',
            'claude-3-opus-20240229' => 'Claude 3 Opus (most powerful)',
            'claude-3-sonnet-20240229' => 'Claude 3 Sonnet (balance)',
            'claude-3-haiku-20240307' => 'Claude 3 Haiku (fast and cheap)',
        ];
    }

    /**
     * Get information about model limits
     */
    public function getModelLimits(): array
    {
        $limits = [
            'claude-3-5-sonnet-20241022' => ['input' => 200000, 'output' => 8192],
            'claude-3-opus-20240229' => ['input' => 200000, 'output' => 4096],
            'claude-3-sonnet-20240229' => ['input' => 200000, 'output' => 4096],
            'claude-3-haiku-20240307' => ['input' => 200000, 'output' => 4096],
        ];

        return $limits[$this->model] ?? ['input' => 200000, 'output' => 4096];
    }
}

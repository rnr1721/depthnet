<?php

namespace App\Services\Agent\Models;

use App\Contracts\Agent\AIModelInterface;
use App\Exceptions\AiModelException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

class Phi3Model implements AIModelInterface
{
    use AiModelTrait;

    public function __construct(
        protected HttpFactory $http,
        protected string $serverUrl = "http://localhost:8080",
        protected array $config = []
    ) {
        // Default settings for Phi
        $this->config = array_merge([
            'model' => 'phi3',
            'temperature' => 0.8,
            'max_tokens' => 2048,
            'top_p' => 0.9,
            'top_k' => 40,
            'repeat_penalty' => 1.1,
            //'stop' => ['<|end|>', '<|user|>', '<|assistant|>']
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

            $data = array_merge($this->config, [
                'messages' => $messages,
                'stream' => false
            ]);

            $response = $this->http->timeout(3800)
                ->post($this->serverUrl . '/v1/chat/completions', $data);

            if ($response->failed()) {
                throw new AiModelException("HTTP Error: " . $response->status() . " - " . $response->body());
            }

            $result = $response->json();

            if (!isset($result['choices'][0]['message']['content'])) {
                Log::warning("Invalid Phi-3 response format", ['response' => $result]);
                throw new AiModelException("Invalid response format from Phi-3");
            }

            return $result['choices'][0]['message']['content'] ?? '';
            //return $this->cleanOutput($result['choices'][0]['message']['content']);

        } catch (\Exception $e) {
            Log::error("Phi3Model error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'context_size' => count($context),
                'config' => $this->config
            ]);
            return "error\nError generating response: " . $e->getMessage();
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
            return "response_from_model\nError: Phi did not respond. You may need to check your settings.";
        }

        $cleanOutput = preg_replace('/\x1b\[[0-9;]*m/', '', $output);

        $cleanOutput = preg_replace('/<\|end\|>|<\|user\|>|<\|assistant\|>|<\|system\|>/', '', $cleanOutput);

        $cleanOutput = preg_replace('/^(assistant|user|system):\s*/i', '', $cleanOutput);

        $cleanOutput = trim($cleanOutput);

        if (empty($cleanOutput)) {
            return "response_from_model\nError: Phi returned an empty response.";
        }

        return $cleanOutput;
    }

    public function getName(): string
    {
        return 'phi';
    }

    /**
     * Specific settings for Phi
     *
     * @param array $newConfig
     * @return void
     */
    public function updateConfig(array $newConfig): void
    {
        $this->config = array_merge($this->config, $newConfig);
    }

    /**
     * Get current settings
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Optimized settings for different modes
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
                    'repeat_penalty' => 1.05
                ]);
                break;

            case 'focused':
                $this->config = array_merge($this->config, [
                    'temperature' => 0.6,
                    'top_p' => 0.8,
                    'repeat_penalty' => 1.15
                ]);
                break;

            case 'balanced':
            default:
                $this->config = array_merge($this->config, [
                    'temperature' => 0.8,
                    'top_p' => 0.9,
                    'repeat_penalty' => 1.1
                ]);
                break;
        }
    }
}

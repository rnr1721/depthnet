<?php

namespace App\Services\Agent\Models;

use App\Contracts\Agent\AIModelInterface;
use App\Exceptions\AiModelException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

class LlamaModel implements AIModelInterface
{
    use AiModelTrait;

    public function __construct(
        protected HttpFactory $http,
        protected string $serverUrl = "http://localhost:8080",
        protected array $config = []
    ) {
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
            Log::info('current context', [$messages]);
            $data = array_merge($this->config, [
                'messages' => $messages,
                'stream' => false
            ]);

            $response = $this->http->timeout(5600)
                ->post($this->serverUrl . '/chat/completions', $data);

            if ($response->failed()) {
                throw new AiModelException("HTTP Error: " . $response->status());
            }

            $result = $response->json();
            if (!isset($result['choices'][0]['message']['content'])) {
                throw new AiModelException("Invalid response format");
            }

            if (empty($result['choices'][0]['message']['content'])) {
                Log::info('empty', [$result]);
            }

            return $result['choices'][0]['message']['content'] ?? '';
            //return $this->cleanOutput($result['choices'][0]['message']['content']);

        } catch (\Exception $e) {
            Log::error("LlamaModel error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
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
                            'content' => 'Please resume thinking'
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

        return $messages;
    }

    protected function cleanOutput(?string $output): string
    {
        if (empty($output)) {
            return "response_from_model\nError: The model did not respond. You need to check your settings and try again.";
        }

        // Remove possible terminal escape sequences
        $cleanOutput = preg_replace('/\x1b\[[0-9;]*m/', '', $output);

        // Delete all model tags
        $cleanOutput = preg_replace('/<\|eot_id\|>|<\|start_header_id\|>user|<\|start_header_id\|>system|<\|start_header_id\|>assistant/', '', $cleanOutput);
        return trim($cleanOutput);
    }

    public function getName(): string
    {
        return 'llama';
    }
}

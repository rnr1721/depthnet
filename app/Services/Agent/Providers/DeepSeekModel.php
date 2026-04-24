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
 * DeepSeek AI engine implementation
 *
 * Supports DeepSeek-V3.2 via DeepSeek API (OpenAI-compatible).
 * Two modes: deepseek-chat (standard) and deepseek-reasoner (thinking/CoT).
 * Reasoner responses include reasoning_content (CoT) which is stripped from context
 * on subsequent turns per DeepSeek multi-turn spec.
 */
class DeepSeekModel implements AIModelEngineInterface
{
    use AiModelPromptTrait;

    protected string $serverUrl;
    protected string $apiKey;
    protected string $model;

    /** Model IDs that return reasoning_content */
    protected const REASONING_MODELS = [
        'deepseek-reasoner',
    ];

    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
        protected CacheManager $cache,
        protected array $config = []
    ) {
        $defaultConfig = config('ai.engines.deepseek', []);
        $this->config  = array_merge($this->getDefaultConfig(), $defaultConfig, $config);

        $this->serverUrl = $this->config['server_url'];
        $this->apiKey    = $config['api_key'] ?? $this->config['api_key'] ?? '';
        $this->model     = $config['model']   ?? $this->config['model'];
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'deepseek';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return config('ai.engines.deepseek.display_name', 'DeepSeek');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return config(
            'ai.engines.deepseek.description',
            'DeepSeek provides powerful language models including DeepSeek-V3.2 in standard and thinking (CoT) modes. OpenAI-compatible API with 128K context window.'
        );
    }

    /**
     * @inheritDoc
     */
    public function supportsDynamicModels(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function requiresApiKeyForModels(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        $validation = config('ai.engines.deepseek.validation', []);

        return [
            'api_key' => [
                'type'        => 'password',
                'label'       => 'API Key',
                'description' => 'Get your API key from https://platform.deepseek.com/api_keys',
                'placeholder' => 'sk-...',
                'required'    => true,
            ],
            'model' => [
                'type'             => 'dynamic_models',
                'label'            => 'Model',
                'description'      => 'deepseek-chat — standard mode, deepseek-reasoner — thinking/CoT mode',
                'loading_text'     => 'Loading available DeepSeek models...',
                'error_text'       => 'Failed to load models. Using fallback list.',
                'fallback_options' => $this->getFallbackModelOptions(),
                'required'         => true,
            ],
            'temperature' => [
                'type'        => 'number',
                'label'       => 'Temperature',
                'description' => 'Recommended: 0.0 for coding/math, 1.0 for data analysis, 1.3 for conversation/translation, 1.5 for creative writing',
                'min'         => $validation['temperature']['min'] ?? 0,
                'max'         => $validation['temperature']['max'] ?? 2,
                'step'        => 0.1,
                'required'    => false,
            ],
            'top_p' => [
                'type'     => 'number',
                'label'    => 'Top P',
                'description' => 'Nucleus sampling — alternative to temperature',
                'min'      => $validation['top_p']['min'] ?? 0,
                'max'      => $validation['top_p']['max'] ?? 1,
                'step'     => 0.05,
                'required' => false,
            ],
            'max_tokens' => [
                'type'        => 'number',
                'label'       => 'Max tokens',
                'description' => 'Max output tokens. deepseek-chat: up to 8K, deepseek-reasoner: up to 64K',
                'min'         => $validation['max_tokens']['min'] ?? 1,
                'max'         => $validation['max_tokens']['max'] ?? 65536,
                'required'    => false,
            ],
            'frequency_penalty' => [
                'type'     => 'number',
                'label'    => 'Frequency penalty',
                'description' => 'Reduces likelihood of repeating frequently used tokens',
                'min'      => $validation['frequency_penalty']['min'] ?? -2,
                'max'      => $validation['frequency_penalty']['max'] ?? 2,
                'step'     => 0.1,
                'required' => false,
            ],
            'presence_penalty' => [
                'type'     => 'number',
                'label'    => 'Presence penalty',
                'description' => 'Reduces likelihood of repeating already used tokens',
                'min'      => $validation['presence_penalty']['min'] ?? -2,
                'max'      => $validation['presence_penalty']['max'] ?? 2,
                'step'     => 0.1,
                'required' => false,
            ],
            'agent_results_role' => [
                'type'        => 'select',
                'label'       => 'Role for Agent Results in context',
                'description' => 'Role for agent command results in conversation context',
                'options'     => [
                    'system'    => 'system',
                    'assistant' => 'assistant',
                    'user'      => 'user',
                    'tool'      => 'tool',
                ],
                'required' => false,
            ],
            'system_prompt' => [
                'type'        => 'textarea',
                'label'       => 'System prompt',
                'description' => 'Instructions for the model. Note: deepseek-reasoner ignores system prompt in some configurations.',
                'placeholder' => 'You are a useful AI assistant...',
                'required'    => false,
                'rows'        => 6,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getDefaultConfig(): array
    {
        return [
            'model'             => config('ai.engines.deepseek.model', 'deepseek-chat'),
            'server_url'        => config('ai.engines.deepseek.server_url', 'https://api.deepseek.com/chat/completions'),
            'models_endpoint'   => config('ai.engines.deepseek.models_endpoint', 'https://api.deepseek.com/models'),
            'max_tokens'        => (int)   config('ai.engines.deepseek.max_tokens', 4096),
            'temperature'       => (float) config('ai.engines.deepseek.temperature', 1.0),
            'top_p'             => (float) config('ai.engines.deepseek.top_p', 1.0),
            'frequency_penalty' => (float) config('ai.engines.deepseek.frequency_penalty', 0.0),
            'presence_penalty'  => (float) config('ai.engines.deepseek.presence_penalty', 0.0),
            'api_key'           => config('ai.engines.deepseek.api_key', ''),
            'agent_results_role' => config('ai.engines.deepseek.agent_results_role', 'assistant'),
            'system_prompt'     => config('ai.engines.deepseek.system_prompt', 'You are a useful AI assistant.'),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getRecommendedPresets(): array
    {
        $configPresets = config('ai.engines.deepseek.recommended_presets', []);
        if (!empty($configPresets)) {
            return $configPresets;
        }

        return [
            [
                'name'        => 'DeepSeek V3 - Conversation',
                'description' => 'DeepSeek-chat optimized for general conversation',
                'config'      => [
                    'model'       => 'deepseek-chat',
                    'temperature' => 1.3,
                    'top_p'       => 1.0,
                    'max_tokens'  => 4096,
                ],
            ],
            [
                'name'        => 'DeepSeek V3 - Coding',
                'description' => 'DeepSeek-chat optimized for coding and math tasks',
                'config'      => [
                    'model'       => 'deepseek-chat',
                    'temperature' => 0.0,
                    'top_p'       => 1.0,
                    'max_tokens'  => 8192,
                ],
            ],
            [
                'name'        => 'DeepSeek Reasoner - Thinking',
                'description' => 'DeepSeek-reasoner with extended Chain-of-Thought reasoning',
                'config'      => [
                    'model'       => 'deepseek-reasoner',
                    'temperature' => 1.0,
                    'top_p'       => 1.0,
                    'max_tokens'  => 32768,
                ],
            ],
            [
                'name'        => 'DeepSeek V3 - Creative',
                'description' => 'DeepSeek-chat optimized for creative writing and poetry',
                'config'      => [
                    'model'       => 'deepseek-chat',
                    'temperature' => 1.5,
                    'top_p'       => 1.0,
                    'max_tokens'  => 4096,
                ],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateConfig(array $config): array
    {
        $errors     = [];
        $validation = config('ai.engines.deepseek.validation', []);

        if (empty($config['api_key'])) {
            $errors['api_key'] = 'API key is required';
        }

        if (empty($config['model'])) {
            $errors['model'] = 'Model name is required';
        }

        foreach (['temperature', 'top_p', 'frequency_penalty', 'presence_penalty', 'max_tokens'] as $field) {
            if (isset($config[$field], $validation[$field])) {
                $value = (float) $config[$field];
                $min   = $validation[$field]['min'] ?? null;
                $max   = $validation[$field]['max'] ?? null;

                if ($min !== null && $max !== null && ($value < $min || $value > $max)) {
                    $label          = ucfirst(str_replace('_', ' ', $field));
                    $errors[$field] = "{$label} must be between {$min} and {$max}";
                }
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
                ->withHeaders($this->getRequestHeaders())
                ->timeout(config('ai.engines.deepseek.timeout', 10))
                ->get($this->config['models_endpoint']);

            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    /**
     * Generate an AI response, optionally with tool_calls support.
     *
     * When the preset operates in command_mode='tool_calls', Agent attaches
     * OpenAI-compatible tool schemas via ModelRequestDTO::additionalParams['tools'].
     * This method forwards them to the DeepSeek API and handles two response paths:
     *
     * - finish_reason='tool_calls': model wants to invoke plugins.
     *   tool_calls are serialized as JSON and returned as the response string.
     *   ToolCallParser in AgentActions will deserialize and execute them.
     *
     * - finish_reason='stop' (or absent): normal text response.
     *   Processed identically to the non-tool_calls path.
     *
     * @param  AiModelRequestInterface  $request
     * @return AiModelResponseInterface
     */
    public function generate(AiModelRequestInterface $request): AiModelResponseInterface
    {
        try {
            $messages = $this->buildMessages($request);

            $data = [
                'model'             => $this->model,
                'messages'          => $messages,
                'max_tokens'        => (int)   $this->config['max_tokens'],
                'temperature'       => (float) $this->config['temperature'],
                'top_p'             => (float) $this->config['top_p'],
                'frequency_penalty' => (float) $this->config['frequency_penalty'],
                'presence_penalty'  => (float) $this->config['presence_penalty'],
                'stream'            => false,
            ];

            // Attach tool schemas when preset is in tool_calls mode.
            // tool_choice='auto' lets the model decide whether to call a tool
            // or respond with plain text — we never force a tool call.
            $tools = $request->getAdditionalParam('tools', []);
            if (!empty($tools)) {
                $data['tools']       = $tools;
                $data['tool_choice'] = 'auto';
            }

            $response = $this->http
                ->withHeaders($this->getRequestHeaders())
                ->timeout((int) config('ai.engines.deepseek.timeout', 120))
                ->post($this->serverUrl, $data);

            if ($response->failed()) {
                $errorBody    = $response->json();
                $errorMessage = $errorBody['error']['message'] ?? 'Unknown error';
                throw new AiModelException("DeepSeek API Error ({$response->status()}): {$errorMessage}");
            }

            $result      = $response->json();
            $choice      = $result['choices'][0] ?? null;
            $finishReason = $choice['finish_reason'] ?? null;
            $message     = $choice['message'] ?? null;

            // Log token usage if enabled
            if (isset($result['usage']) && config('ai.engines.deepseek.log_usage', true)) {
                $this->logger->info('DeepSeek tokens used', [
                    'model'             => $this->model,
                    'prompt_tokens'     => $result['usage']['prompt_tokens'],
                    'completion_tokens' => $result['usage']['completion_tokens'],
                    'total_tokens'      => $result['usage']['total_tokens'],
                ]);
            }

            // Tool-calls path: model chose to invoke one or more plugins.
            // Serialize tool_calls as JSON — ToolCallParser::parse() expects this format:
            //   {"tool_calls":[{"type":"function","function":{"name":"..","arguments":"{..}"}}]}
            if ($finishReason === 'tool_calls' && !empty($message['tool_calls'])) {
                $this->logger->info('DeepSeek tool_calls received', [
                    'model' => $this->model,
                    'count' => count($message['tool_calls']),
                ]);

                $reasoningContent = $message['reasoning_content'] ?? null;

                return new ModelResponseDTO(
                    json_encode(['tool_calls' => $message['tool_calls']]),
                    false,
                    $reasoningContent ? ['reasoning_content' => $reasoningContent] : []
                );
            }

            // Normal text response path
            if (!isset($message['content'])) {
                $this->logger->warning('Invalid DeepSeek response format', [
                    'response' => $result,
                    'model'    => $this->model,
                ]);
                throw new AiModelException(
                    config('ai.global.error_messages.invalid_format', 'Invalid response format from DeepSeek API')
                );
            }

            // Log reasoning_content when using deepseek-reasoner (CoT mode)
            $reasoningContent = $message['reasoning_content'] ?? null;
            if ($reasoningContent) {
                $this->logger->info('DeepSeek reasoning content received', [
                    'model'            => $this->model,
                    'reasoning_length' => strlen($reasoningContent),
                ]);
            }

            return new ModelResponseDTO(
                $this->cleanOutput($message['content']),
                false,
                $reasoningContent ? ['reasoning_content' => $reasoningContent] : []
            );

        } catch (\Exception $e) {
            $this->logger->error('DeepSeekModel error: ' . $e->getMessage(), [
                'trace'        => $e->getTraceAsString(),
                'context_size' => count($request->getContext()),
                'model'        => $this->model,
            ]);

            return $this->handleError($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function getAvailableModels(?array $config = null): array
    {
        $apiKey = $config['api_key'] ?? $this->apiKey ?? '';

        if (empty($apiKey)) {
            $this->logger->info('No DeepSeek API key provided, using fallback models');
            return $this->getFallbackModels();
        }

        $cacheKey      = 'deepseek_models_' . substr(md5($apiKey), 0, 8);
        $cacheLifetime = config('ai.engines.deepseek.models_cache_lifetime', 3600);

        return $this->cache->remember($cacheKey, $cacheLifetime, function () use ($apiKey) {
            try {
                $response = $this->http
                    ->withHeaders([
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . $apiKey,
                    ])
                    ->timeout(config('ai.engines.deepseek.timeout', 30))
                    ->get($this->config['models_endpoint']);

                if ($response->successful()) {
                    $data   = $response->json();
                    $models = [];

                    foreach ($data['data'] ?? [] as $model) {
                        $modelId = $model['id'] ?? '';
                        if (empty($modelId)) {
                            continue;
                        }

                        $models[$modelId] = [
                            'id'           => $modelId,
                            'display_name' => $this->formatModelDisplayName($modelId),
                            'owned_by'     => $model['owned_by'] ?? 'deepseek',
                            'source'       => 'api',
                            'recommended'  => $this->isRecommendedModel($modelId),
                        ];
                    }

                    if (!empty($models)) {
                        $this->logger->info('DeepSeek models fetched from API', ['count' => count($models)]);
                        return $models;
                    }
                }

                $this->logger->warning('Failed to fetch DeepSeek models from API', [
                    'status' => $response->status(),
                ]);

            } catch (\Exception $e) {
                $this->logger->error('Error fetching DeepSeek models: ' . $e->getMessage());
            }

            return $this->getFallbackModels();
        });
    }

    /**
     * @inheritDoc
     */
    public function updateConfig(array $newConfig): void
    {
        $this->config = array_merge($this->config, $newConfig);

        if (isset($newConfig['model'])) {
            $this->model = $newConfig['model'];
        }

        if (isset($newConfig['api_key'])) {
            $this->apiKey = $newConfig['api_key'];
        }

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
            'model'   => $this->model,
            'api_key' => $this->apiKey ? substr($this->apiKey, 0, 8) . '...' : '',
        ]);
    }

    /**
     * @inheritDoc
     */
    public function setMode(string $mode): void
    {
        $modePresets = config('ai.engines.deepseek.mode_presets', []);

        if (isset($modePresets[$mode])) {
            $this->config = array_merge($this->config, $modePresets[$mode]);
            return;
        }

        switch ($mode) {
            case 'creative':
                $this->config = array_merge($this->config, [
                    'temperature' => 1.5,
                    'top_p'       => 1.0,
                    'max_tokens'  => 4096,
                ]);
                break;

            case 'focused':
                $this->config = array_merge($this->config, [
                    'temperature' => 0.0,
                    'top_p'       => 1.0,
                    'max_tokens'  => 8192,
                ]);
                break;

            case 'balanced':
            default:
                $this->config = array_merge($this->config, [
                    'temperature' => 1.0,
                    'top_p'       => 1.0,
                    'max_tokens'  => 4096,
                ]);
                break;
        }
    }

    /**
     * Clear models cache
     */
    public function clearModelsCache(): void
    {
        $cacheKey = 'deepseek_models_' . substr(md5($this->apiKey), 0, 8);
        $this->cache->forget($cacheKey);
    }

    /**
     * Check if current model is a reasoning/thinking model
     */
    public function isReasoningModel(): bool
    {
        return in_array($this->model, self::REASONING_MODELS, true);
    }

    // ─── Protected helpers ────────────────────────────────────────────────────

    /**
     * Build messages array for the DeepSeek API.
     *
     * DeepSeek is OpenAI-compatible, so the system prompt goes as the first
     * message. Per DeepSeek spec, reasoning_content from previous turns must
     * NOT be included in context (we never store it, so nothing to do here).
     *
     * Role mapping:
     *   user    → role: user
     *   command → role: assistant + tool_calls (if tool_calls_raw in metadata)
     *             role: assistant (plain, for tag-mode command messages)
     *   result  → role: tool + tool_call_id    (if tool_call_id in metadata)
     *             role: agent_results_role      (plain, for tag-mode result messages)
     *   default → role: assistant
     *
     * @param  AiModelRequestInterface $request
     * @return array                   Messages array ready for the DeepSeek API
     */
    protected function buildMessages(AiModelRequestInterface $request): array
    {

        $systemMessage = $this->prepareMessage($request);
        $messages      = [];

        if (!empty(trim($systemMessage))) {
            $messages[] = [
                'role'    => 'system',
                'content' => $systemMessage,
            ];
        }

        foreach ($request->getContext() as $entry) {
            $role    = $entry['role']    ?? 'assistant';
            $content = $entry['content'] ?? '';
            $metadata = $entry['metadata'] ?? [];

            $hasToolCalls   = $role === 'command' && !empty($metadata['tool_calls_raw']);
            $hasToolResults = $role === 'result'  && !empty($metadata['tool_results']);

            if (empty(trim((string)$content)) && !$hasToolCalls && !$hasToolResults) {
                continue;
            }

            switch ($role) {
                case 'user':
                    $messages[] = ['role' => 'user', 'content' => $content];
                    break;
                case 'command':
                    // tool_calls mode: restore structured assistant+tool_calls turn.
                    // The engine stored the raw tool_calls JSON in metadata when it
                    // detected finish_reason='tool_calls' from the API.
                    $toolCallsRaw = $metadata['tool_calls_raw'] ?? null;
                    $reasoningContent = $metadata['reasoning_content'] ?? null;
                    if ($toolCallsRaw) {
                        $decoded    = json_decode($toolCallsRaw, true);
                        $toolCalls  = $decoded['tool_calls'] ?? [];

                        if (!empty($toolCalls)) {
                            // Emit null content — OpenAI spec requires content=null
                            // when tool_calls are present in the assistant turn
                            $assistantMsg = [
                                'role'       => 'assistant',
                                'content'    => null,
                                'tool_calls' => $toolCalls,
                            ];

                            if ($reasoningContent) {
                                $assistantMsg['reasoning_content'] = $reasoningContent;
                            }

                            $messages[] = $assistantMsg;
                            break;
                        }
                    }

                    $msg = ['role' => 'assistant', 'content' => $content];
                    if ($reasoningContent) {
                        $msg['reasoning_content'] = $reasoningContent;
                    }
                    $messages[] = $msg;
                    break;
                case 'result':
                    $toolResults = $metadata['tool_results'] ?? null;
                    if ($toolResults && is_array($toolResults)) {
                        foreach ($toolResults as $tr) {
                            if (empty($tr['tool_call_id'])) {
                                continue;
                            }
                            $messages[] = [
                                'role'         => 'tool',
                                'tool_call_id' => $tr['tool_call_id'],
                                'content'      => $tr['content'] ?? '',
                            ];
                        }
                    } else {
                        $fallbackRole = $this->config['agent_results_role'] ?? 'assistant';
                        if ($fallbackRole === 'tool') {
                            $fallbackRole = 'assistant';
                        }
                        $messages[] = [
                            'role'    => $fallbackRole,
                            'content' => $content,
                        ];
                    }
                    break;
                default:
                    $messages[] = ['role' => 'assistant', 'content' => $content];
                    break;
            }
        }
        return $messages;
    }

    /**
     * Get request headers
     */
    protected function getRequestHeaders(): array
    {
        return [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];
    }

    /**
     * Clean output
     */
    protected function cleanOutput(?string $output): string
    {
        if (empty($output)) {
            return 'response_from_model\n' . config(
                'ai.global.error_messages.empty_response',
                'Error: DeepSeek did not provide a response.'
            );
        }

        $clean = trim($output);

        $cleanupPattern = config('ai.engines.deepseek.cleanup.role_prefixes', '/^(Assistant|AI):\s*/i');
        $clean          = preg_replace($cleanupPattern, '', $clean);

        if (empty($clean)) {
            return 'response_from_model\n' . config(
                'ai.global.error_messages.empty_response',
                'Error: DeepSeek returned an empty response.'
            );
        }

        return $clean;
    }

    /**
     * Handle API errors with specific DeepSeek error codes
     */
    protected function handleError(\Exception $e): ModelResponseDTO
    {
        $errorMessage = $e->getMessage();
        $errorMessages = config('ai.engines.deepseek.error_messages', []);

        $this->logger->error('DeepSeek Error Details', [
            'error'      => $errorMessage,
            'model'      => $this->model,
            'server_url' => $this->serverUrl,
        ]);

        if (preg_match('/DeepSeek API Error \((\d+)\)/', $errorMessage, $matches)) {
            $statusCode = (int) $matches[1];

            $message = match ($statusCode) {
                400 => $errorMessages[400] ?? 'Invalid request format. Please check your configuration.',
                401 => $errorMessages[401] ?? 'Authentication failed. Please check your DeepSeek API key.',
                402 => $errorMessages[402] ?? 'Insufficient balance. Please top up your DeepSeek account.',
                422 => $errorMessages[422] ?? 'Invalid parameters. Please check your model configuration.',
                429 => $errorMessages[429] ?? 'Rate limit reached. Please slow down your requests.',
                500 => $errorMessages[500] ?? 'DeepSeek server error. Please try again later.',
                503 => $errorMessages[503] ?? 'DeepSeek server overloaded. Please retry after a short wait.',
                default => $errorMessages['api_error'] ?? "DeepSeek API error: HTTP {$statusCode}",
            };

            return new ModelResponseDTO("error\n{$message}", true);
        }

        if (str_contains($errorMessage, 'Connection refused') || str_contains($errorMessage, 'Could not resolve host')) {
            $message = $errorMessages['connection_failed'] ?? 'Failed to connect to DeepSeek API. Check your internet connection.';
            return new ModelResponseDTO("error\n{$message}", true);
        }

        $message = $errorMessages['general'] ?? 'Error contacting DeepSeek: ' . $errorMessage;
        return new ModelResponseDTO("error\n{$message}", true);
    }

    /**
     * Get fallback models
     */
    protected function getFallbackModels(): array
    {
        return [
            'deepseek-chat' => [
                'id'           => 'deepseek-chat',
                'display_name' => 'DeepSeek V3 (Chat)',
                'owned_by'     => 'deepseek',
                'recommended'  => true,
                'source'       => 'fallback',
            ],
            'deepseek-reasoner' => [
                'id'           => 'deepseek-reasoner',
                'display_name' => 'DeepSeek V3 (Reasoner / CoT)',
                'owned_by'     => 'deepseek',
                'recommended'  => true,
                'source'       => 'fallback',
            ],
        ];
    }

    /**
     * Get fallback model options for select field
     */
    protected function getFallbackModelOptions(): array
    {
        return [
            'deepseek-chat'     => '⭐ DeepSeek V3 (Chat)',
            'deepseek-reasoner' => '⭐ DeepSeek V3 (Reasoner / CoT)',
        ];
    }

    /**
     * Format model display name
     */
    protected function formatModelDisplayName(string $modelId): string
    {
        return match ($modelId) {
            'deepseek-chat'     => 'DeepSeek V3 (Chat)',
            'deepseek-reasoner' => 'DeepSeek V3 (Reasoner / CoT)',
            default             => ucwords(str_replace(['-', '_'], ' ', $modelId)),
        };
    }

    /**
     * Check if model is recommended
     */
    protected function isRecommendedModel(string $modelId): bool
    {
        return in_array($modelId, ['deepseek-chat', 'deepseek-reasoner'], true);
    }
}

<?php

namespace App\Services\Agent\Providers;

use App\Contracts\Agent\AiModelRequestInterface;
use App\Contracts\Agent\AiModelResponseInterface;
use App\Contracts\Agent\Models\AIModelEngineInterface;
use App\Exceptions\AiModelException;
use App\Services\Agent\DTO\ModelResponseDTO;
use App\Services\Agent\Providers\Traits\AiModelPromptTrait;
use App\Services\Agent\Providers\Traits\RetriesTransientErrors;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * OpenRouter engine — one OpenAI-compatible gateway to many vendors
 * (Anthropic, DeepSeek, Qwen, xAI, Google, ...).
 *
 * Model ids are vendor-prefixed slugs: "anthropic/...", "deepseek/...",
 * "qwen/...", "x-ai/...". The list is loaded dynamically from /models.
 *
 * Differences from DeepSeekModel worth knowing:
 *
 *   - Errors can arrive with HTTP 200: a top-level "error" object, or an
 *     "error" inside choices[0] (upstream provider failed after routing).
 *     Both are treated as API errors here.
 *
 *   - Reasoning comes back as "reasoning" (text) + "reasoning_details"
 *     (structured, possibly signed/encrypted blocks). For Anthropic models with
 *     reasoning on, reasoning_details MUST be passed back unchanged on the
 *     assistant tool_calls turn, or the follow-up request fails. Stored in
 *     metadata['reasoning_details'] and re-attached in buildMessages().
 *     metadata['reasoning_content'] carries the readable text for the UI,
 *     same key DeepSeekModel uses.
 *
 *   - Anthropic models: when reasoning is on, temperature/top_p are omitted
 *     (extended thinking rejects custom sampling); and temperature + top_p
 *     are never sent together (newer Claude models accept only one).
 *
 *   - Optional prompt caching (Anthropic models only): the system message is
 *     sent as a content part with cache_control. Only pays off when the system
 *     prompt is byte-identical between cycles — see the config field note.
 */
class OpenRouterModel implements AIModelEngineInterface
{
    use AiModelPromptTrait;
    use RetriesTransientErrors;

    protected string $serverUrl;
    protected string $apiKey;
    protected string $model;

    protected const REASONING_EFFORTS = ['low', 'medium', 'high'];

    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
        protected CacheManager $cache,
        protected array $config = []
    ) {
        $defaultConfig = config('ai.engines.openrouter', []);
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
        return 'openrouter';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return config('ai.engines.openrouter.display_name', 'OpenRouter');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return config(
            'ai.engines.openrouter.description',
            'OpenRouter — one OpenAI-compatible API for many vendors: Anthropic Claude, DeepSeek, Qwen, Grok, Gemini and others. Model ids are vendor-prefixed, e.g. anthropic/..., deepseek/..., qwen/...'
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
     * The /models endpoint is public, so the list loads without a key.
     *
     * @inheritDoc
     */
    public function requiresApiKeyForModels(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        $validation = config('ai.engines.openrouter.validation', []);

        return [
            'api_key' => [
                'type'        => 'password',
                'label'       => 'API Key',
                'description' => 'Get your API key from https://openrouter.ai/keys',
                'placeholder' => 'sk-or-v1-...',
                'required'    => true,
            ],
            'model' => [
                'type'             => 'dynamic_models',
                'label'            => 'Model',
                'description'      => 'Vendor-prefixed model id. Models marked "no tools" cannot be used in tool_calls mode.',
                'loading_text'     => 'Loading OpenRouter models...',
                'error_text'       => 'Failed to load models. Using fallback list.',
                'fallback_options' => $this->getFallbackModelOptions(),
                'required'         => true,
            ],
            'temperature' => [
                'type'        => 'number',
                'label'       => 'Temperature',
                'description' => 'Leave empty to use the model default. Claude accepts 0–1. Ignored for Claude when reasoning is on.',
                'min'         => $validation['temperature']['min'] ?? 0,
                'max'         => $validation['temperature']['max'] ?? 2,
                'step'        => 0.1,
                'required'    => false,
            ],
            'top_p' => [
                'type'        => 'number',
                'label'       => 'Top P',
                'description' => 'Leave empty unless needed. For Claude it is dropped when temperature is set.',
                'min'         => $validation['top_p']['min'] ?? 0,
                'max'         => $validation['top_p']['max'] ?? 1,
                'step'        => 0.05,
                'required'    => false,
            ],
            'max_tokens' => [
                'type'        => 'number',
                'label'       => 'Max tokens',
                'description' => 'Max output tokens. With reasoning on, the thinking budget is taken from this, so keep it generous.',
                'min'         => $validation['max_tokens']['min'] ?? 1,
                'max'         => $validation['max_tokens']['max'] ?? 128000,
                'required'    => false,
            ],
            'reasoning_effort' => [
                'type'        => 'select',
                'label'       => 'Reasoning',
                'description' => 'Model thinking before answering (models that support it). Costs extra output tokens.',
                'options'     => [
                    ''       => 'off',
                    'low'    => 'low',
                    'medium' => 'medium',
                    'high'   => 'high',
                ],
                'required' => false,
            ],
            'prompt_cache' => [
                'type'        => 'checkbox',
                'label'       => 'Prompt caching (Claude only)',
                'description' => 'Caches the system prompt. Only helps if the system prompt is IDENTICAL between cycles. If it contains datetime, RAG, mood or other per-cycle placeholders, every cycle is a cache miss and you pay the cache-write surcharge for nothing.',
                'required'    => false,
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
                'description' => 'Instructions for the model',
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
            'model'              => config('ai.engines.openrouter.model', 'openrouter/auto'),
            'server_url'         => config('ai.engines.openrouter.server_url', 'https://openrouter.ai/api/v1/chat/completions'),
            'models_endpoint'    => config('ai.engines.openrouter.models_endpoint', 'https://openrouter.ai/api/v1/models'),
            'key_endpoint'       => config('ai.engines.openrouter.key_endpoint', 'https://openrouter.ai/api/v1/key'),
            'max_tokens'         => (int) config('ai.engines.openrouter.max_tokens', 8192),
            'temperature'        => config('ai.engines.openrouter.temperature', null),
            'top_p'              => config('ai.engines.openrouter.top_p', null),
            'reasoning_effort'   => config('ai.engines.openrouter.reasoning_effort', ''),
            'prompt_cache'       => (bool) config('ai.engines.openrouter.prompt_cache', false),
            'api_key'            => config('ai.engines.openrouter.api_key', ''),
            'agent_results_role' => config('ai.engines.openrouter.agent_results_role', 'assistant'),
            'system_prompt'      => config('ai.engines.openrouter.system_prompt', 'You are a useful AI assistant.'),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getRecommendedPresets(): array
    {
        $configPresets = config('ai.engines.openrouter.recommended_presets', []);
        if (!empty($configPresets)) {
            return $configPresets;
        }

        return [
            [
                'name'        => 'OpenRouter Auto',
                'description' => 'OpenRouter picks a model per request. Good for a first connection test, not for a persistent agent.',
                'config'      => [
                    'model'      => 'openrouter/auto',
                    'max_tokens' => 4096,
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
        $validation = config('ai.engines.openrouter.validation', []);

        if (empty($config['api_key'])) {
            $errors['api_key'] = 'API key is required';
        }

        if (empty($config['model'])) {
            $errors['model'] = 'Model name is required';
        }

        foreach (['temperature', 'top_p', 'max_tokens'] as $field) {
            if (!isset($config[$field]) || $config[$field] === '' || !isset($validation[$field])) {
                continue;
            }

            $value = (float) $config[$field];
            $min   = $validation[$field]['min'] ?? null;
            $max   = $validation[$field]['max'] ?? null;

            if ($min !== null && $max !== null && ($value < $min || $value > $max)) {
                $label          = ucfirst(str_replace('_', ' ', $field));
                $errors[$field] = "{$label} must be between {$min} and {$max}";
            }
        }

        if (!empty($config['reasoning_effort'])
            && !in_array($config['reasoning_effort'], self::REASONING_EFFORTS, true)) {
            $errors['reasoning_effort'] = 'Reasoning must be off, low, medium or high';
        }

        return $errors;
    }

    /**
     * Checks the key itself, not just reachability: /models is public and
     * would answer 200 even with a wrong key.
     *
     * @inheritDoc
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->http
                ->withHeaders($this->getRequestHeaders())
                ->timeout(config('ai.engines.openrouter.timeout', 10))
                ->get($this->config['key_endpoint']);

            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Generate an AI response, optionally with tool_calls support.
     *
     * OpenRouter normalizes every vendor to the OpenAI tool_calls shape, so
     * ToolCallParser gets the same {"tool_calls":[...]} JSON as from DeepSeek.
     *
     * The tool_calls path is taken whenever message.tool_calls is non-empty,
     * not only on finish_reason='tool_calls': finish_reason is normalized by
     * OpenRouter and some upstreams report 'stop' alongside tool calls.
     *
     * @param  AiModelRequestInterface  $request
     * @return AiModelResponseInterface
     */
    public function generate(AiModelRequestInterface $request): AiModelResponseInterface
    {
        try {
            $messages = $this->buildMessages($request);

            $data = [
                'model'      => $this->model,
                'messages'   => $messages,
                'max_tokens' => (int) $this->config['max_tokens'],
                'stream'     => false,
            ];

            $this->applySampling($data);
            $this->applyReasoning($data);

            $tools = $request->getAdditionalParam('tools', []);
            if (!empty($tools)) {
                $data['tools']       = $tools;
                $data['tool_choice'] = 'auto';
            }

            $response = $this->withTransientRetry(
                $this->http
                    ->withHeaders($this->getRequestHeaders())
                    ->timeout((int) config('ai.engines.openrouter.timeout', 180))
            )->post($this->serverUrl, $data);

            if ($response->failed()) {
                $errorBody    = $response->json();
                $errorMessage = $this->extractErrorMessage($errorBody);
                throw new AiModelException("OpenRouter API Error ({$response->status()}): {$errorMessage}");
            }

            $result = $response->json();

            // HTTP 200 can still carry an error (upstream failure after routing).
            if (isset($result['error'])) {
                $code = (int) ($result['error']['code'] ?? 502);
                throw new AiModelException("OpenRouter API Error ({$code}): " . $this->extractErrorMessage($result));
            }

            $choice = $result['choices'][0] ?? null;

            if (isset($choice['error'])) {
                $code = (int) ($choice['error']['code'] ?? 502);
                $msg  = $choice['error']['message'] ?? 'Upstream provider error';
                throw new AiModelException("OpenRouter API Error ({$code}): {$msg}");
            }

            $finishReason = $choice['finish_reason'] ?? null;
            $message      = $choice['message'] ?? null;

            $this->logUsage($result);

            $reasoningText    = $message['reasoning'] ?? null;
            $reasoningDetails = $message['reasoning_details'] ?? null;

            $reasoningMeta = array_merge(
                is_string($reasoningText) && $reasoningText !== '' ? ['reasoning_content' => $reasoningText] : [],
                !empty($reasoningDetails) ? ['reasoning_details' => $reasoningDetails] : []
            );

            // Tool-calls path.
            if (!empty($message['tool_calls'])) {
                $this->logger->info('OpenRouter tool_calls received', [
                    'model'         => $this->model,
                    'finish_reason' => $finishReason,
                    'count'         => count($message['tool_calls']),
                ]);

                return new ModelResponseDTO(
                    json_encode(['tool_calls' => $message['tool_calls']]),
                    false,
                    array_merge($reasoningMeta, ['system_prompt' => $request->getResolvedSystemPrompt()])
                );
            }

            $content = $this->contentToString($message['content'] ?? null);

            // Empty content with a known finish_reason is a legitimate empty turn
            // (e.g. whole budget spent on reasoning). Same policy as DeepSeekModel.
            if ($content === null || trim($content) === '') {
                $knownReasons = ['stop', 'length', 'content_filter', 'tool_calls'];

                if ($message !== null && in_array($finishReason, $knownReasons, true)) {
                    $this->logger->info('OpenRouter returned empty content (non-error)', [
                        'model'         => $this->model,
                        'finish_reason' => $finishReason,
                    ]);

                    return new ModelResponseDTO(
                        '',
                        false,
                        array_merge($reasoningMeta, [
                            'system_prompt'  => $request->getResolvedSystemPrompt(),
                            'finish_reason'  => $finishReason,
                            'empty_response' => true,
                        ])
                    );
                }

                $this->logger->warning('Invalid OpenRouter response format', [
                    'response' => $result,
                    'model'    => $this->model,
                ]);
                throw new AiModelException(
                    config('ai.global.error_messages.invalid_format', 'Invalid response format from OpenRouter API')
                );
            }

            return new ModelResponseDTO(
                $this->cleanOutput($content),
                false,
                array_merge($reasoningMeta, ['system_prompt' => $request->getResolvedSystemPrompt()])
            );

        } catch (\Exception $e) {
            $this->logger->error('OpenRouterModel error: ' . $e->getMessage(), [
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
        $cacheKey      = 'openrouter_models';
        $cacheLifetime = config('ai.engines.openrouter.models_cache_lifetime', 3600);

        return $this->cache->remember($cacheKey, $cacheLifetime, function () {
            try {
                $response = $this->http
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->timeout(config('ai.engines.openrouter.timeout', 30))
                    ->get($this->config['models_endpoint']);

                if ($response->successful()) {
                    $models = [];

                    foreach ($response->json('data') ?? [] as $model) {
                        $modelId = $model['id'] ?? '';
                        if ($modelId === '') {
                            continue;
                        }

                        // Text-out models only: image/audio generators are useless as agents.
                        $outputs = $model['architecture']['output_modalities'] ?? ['text'];
                        if (!in_array('text', $outputs, true)) {
                            continue;
                        }

                        $supportsTools = in_array('tools', $model['supported_parameters'] ?? [], true);
                        $name          = $model['name'] ?? $this->formatModelDisplayName($modelId);

                        $models[$modelId] = [
                            'id'             => $modelId,
                            'display_name'   => $supportsTools ? $name : $name . ' (no tools)',
                            'owned_by'       => strtok($modelId, '/') ?: 'openrouter',
                            'context_length' => $model['context_length'] ?? null,
                            'supports_tools' => $supportsTools,
                            'source'         => 'api',
                            'recommended'    => $this->isRecommendedModel($modelId),
                        ];
                    }

                    if (!empty($models)) {
                        ksort($models);
                        $this->logger->info('OpenRouter models fetched from API', ['count' => count($models)]);
                        return $models;
                    }
                }

                $this->logger->warning('Failed to fetch OpenRouter models from API', [
                    'status' => $response->status(),
                ]);

            } catch (\Exception $e) {
                $this->logger->error('Error fetching OpenRouter models: ' . $e->getMessage());
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
        $modePresets = config('ai.engines.openrouter.mode_presets', []);

        if (isset($modePresets[$mode])) {
            $this->config = array_merge($this->config, $modePresets[$mode]);
            return;
        }

        switch ($mode) {
            case 'creative':
                $this->config = array_merge($this->config, ['temperature' => 1.0]);
                break;

            case 'focused':
                $this->config = array_merge($this->config, ['temperature' => 0.2]);
                break;

            case 'balanced':
            default:
                $this->config = array_merge($this->config, ['temperature' => 0.7]);
                break;
        }
    }

    /**
     * Clear models cache
     */
    public function clearModelsCache(): void
    {
        $this->cache->forget('openrouter_models');
    }

    // ─── Protected helpers ────────────────────────────────────────────────────

    /**
     * Build messages array for the OpenRouter API (OpenAI format).
     *
     * Same role mapping as DeepSeekModel. Two differences:
     *
     *   - system message: when prompt_cache is on and the model is Anthropic,
     *     it is sent as a content-part array with cache_control (the only way
     *     OpenRouter passes Anthropic cache breakpoints through).
     *
     *   - assistant tool_calls turn: re-attaches reasoning_details from metadata.
     *     Required for Anthropic models with reasoning on; harmless otherwise.
     *     Plain (non-tool) assistant turns get nothing re-attached.
     *
     * @param  AiModelRequestInterface $request
     * @return array
     */
    protected function buildMessages(AiModelRequestInterface $request): array
    {
        $systemMessage = $this->prepareMessage($request);
        $messages      = [];

        if (!empty(trim($systemMessage))) {
            $messages[] = [
                'role'    => 'system',
                'content' => $this->shouldCachePrompt()
                    ? [[
                        'type'          => 'text',
                        'text'          => $systemMessage,
                        'cache_control' => ['type' => 'ephemeral'],
                    ]]
                    : $systemMessage,
            ];
        }

        foreach ($request->getContext() as $entry) {
            $role     = $entry['role']     ?? 'assistant';
            $content  = $entry['content']  ?? '';
            $metadata = $entry['metadata'] ?? [];

            $hasToolCalls   = $role === 'command' && !empty($metadata['tool_calls_raw']);
            $hasToolResults = $role === 'result'  && !empty($metadata['tool_results']);

            if (empty(trim((string) $content)) && !$hasToolCalls && !$hasToolResults) {
                continue;
            }

            switch ($role) {
                case 'user':
                    $messages[] = ['role' => 'user', 'content' => $content];
                    break;

                case 'command':
                    $toolCallsRaw = $metadata['tool_calls_raw'] ?? null;
                    if ($toolCallsRaw) {
                        $decoded   = json_decode($toolCallsRaw, true);
                        $toolCalls = $decoded['tool_calls'] ?? [];

                        if (!empty($toolCalls)) {
                            $assistantMsg = [
                                'role'       => 'assistant',
                                'content'    => null,
                                'tool_calls' => $toolCalls,
                            ];

                            if (!empty($metadata['reasoning_details'])) {
                                $assistantMsg['reasoning_details'] = $metadata['reasoning_details'];
                            }

                            $messages[] = $assistantMsg;
                            break;
                        }
                    }

                    $messages[] = ['role' => 'assistant', 'content' => $content];
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
     * Sampling params, sent only when explicitly set.
     *
     * Anthropic rules: no custom sampling with reasoning on; never temperature
     * and top_p together.
     */
    protected function applySampling(array &$data): void
    {
        $isAnthropic = $this->isAnthropicModel();

        if ($isAnthropic && $this->reasoningEffort() !== null) {
            return;
        }

        $temperature = $this->config['temperature'] ?? null;
        if ($temperature !== null && $temperature !== '') {
            $data['temperature'] = (float) $temperature;
        }

        $topP = $this->config['top_p'] ?? null;
        if ($topP !== null && $topP !== '') {
            if ($isAnthropic && isset($data['temperature'])) {
                return;
            }
            $data['top_p'] = (float) $topP;
        }
    }

    protected function applyReasoning(array &$data): void
    {
        $effort = $this->reasoningEffort();
        if ($effort !== null) {
            $data['reasoning'] = ['effort' => $effort];
        }
    }

    protected function reasoningEffort(): ?string
    {
        $effort = $this->config['reasoning_effort'] ?? '';

        return in_array($effort, self::REASONING_EFFORTS, true) ? $effort : null;
    }

    protected function isAnthropicModel(): bool
    {
        return str_starts_with($this->model, 'anthropic/');
    }

    protected function shouldCachePrompt(): bool
    {
        return !empty($this->config['prompt_cache']) && $this->isAnthropicModel();
    }

    /**
     * Content is usually a string; tolerate a content-part array.
     */
    protected function contentToString(mixed $content): ?string
    {
        if ($content === null || is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text' && isset($part['text'])) {
                    $parts[] = $part['text'];
                }
            }
            return implode("\n", $parts);
        }

        return null;
    }

    protected function extractErrorMessage(?array $body): string
    {
        $message = $body['error']['message'] ?? 'Unknown error';

        // Upstream provider's own error text, when OpenRouter passes it through.
        $raw = $body['error']['metadata']['raw'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $message .= ' | upstream: ' . mb_substr($raw, 0, 500);
        }

        return $message;
    }

    protected function logUsage(array $result): void
    {
        if (!isset($result['usage']) || !config('ai.engines.openrouter.log_usage', true)) {
            return;
        }

        $usage = $result['usage'];

        $this->logger->info('OpenRouter tokens used', array_filter([
            'model'             => $this->model,
            'routed_to'         => $result['provider'] ?? null,
            'prompt_tokens'     => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens'      => $usage['total_tokens'] ?? null,
            'cached_tokens'     => $usage['prompt_tokens_details']['cached_tokens'] ?? null,
            'reasoning_tokens'  => $usage['completion_tokens_details']['reasoning_tokens'] ?? null,
            'cost'              => $usage['cost'] ?? null,
        ], fn ($v) => $v !== null));
    }

    protected function getRequestHeaders(): array
    {
        return array_filter([
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
            // Optional attribution headers (shown on openrouter.ai rankings).
            'HTTP-Referer'  => config('ai.engines.openrouter.referer', config('app.url')),
            'X-Title'       => config('ai.engines.openrouter.app_title', 'DepthNet'),
        ], fn ($v) => !empty($v));
    }

    protected function cleanOutput(?string $output): string
    {
        if (empty($output)) {
            return "response_from_model\n" . config(
                'ai.global.error_messages.empty_response',
                'Error: OpenRouter model did not provide a response.'
            );
        }

        $clean = trim($output);

        $cleanupPattern = config('ai.engines.openrouter.cleanup.role_prefixes', '/^(Assistant|AI):\s*/i');
        $clean          = preg_replace($cleanupPattern, '', $clean);

        if (empty($clean)) {
            return "response_from_model\n" . config(
                'ai.global.error_messages.empty_response',
                'Error: OpenRouter model returned an empty response.'
            );
        }

        return $clean;
    }

    /**
     * OpenRouter status codes: 402 = out of credits, 403 = moderation,
     * 408 = timeout, 502 = upstream provider down, 503 = no provider available.
     */
    protected function handleError(\Exception $e): ModelResponseDTO
    {
        $errorMessage  = $e->getMessage();
        $errorMessages = config('ai.engines.openrouter.error_messages', []);

        if (preg_match('/OpenRouter API Error \((\d+)\)/', $errorMessage, $matches)) {
            $statusCode = (int) $matches[1];

            $message = match ($statusCode) {
                400 => $errorMessages[400] ?? 'Invalid request. Check model id and parameters.',
                401 => $errorMessages[401] ?? 'Authentication failed. Check your OpenRouter API key.',
                402 => $errorMessages[402] ?? 'Out of credits. Top up your OpenRouter balance.',
                403 => $errorMessages[403] ?? 'Request blocked by moderation.',
                408 => $errorMessages[408] ?? 'Request timed out.',
                429 => $errorMessages[429] ?? 'Rate limit reached. Please slow down.',
                502 => $errorMessages[502] ?? 'Upstream model provider is down or returned an invalid response.',
                503 => $errorMessages[503] ?? 'No available provider for this model right now.',
                default => $errorMessages['api_error'] ?? "OpenRouter API error: HTTP {$statusCode}",
            };

            // Keep the original text: upstream details matter when debugging.
            return new ModelResponseDTO(
                "error\n{$message}\n{$errorMessage}",
                true,
                $this->errorKindMeta($statusCode, $errorMessage)
            );
        }

        if (str_contains($errorMessage, 'Connection refused') || str_contains($errorMessage, 'Could not resolve host')) {
            $message = $errorMessages['connection_failed'] ?? 'Failed to connect to OpenRouter. Check your internet connection.';
            return new ModelResponseDTO("error\n{$message}", true, $this->errorKindMeta(null, $errorMessage));
        }

        $message = $errorMessages['general'] ?? 'Error contacting OpenRouter: ' . $errorMessage;
        return new ModelResponseDTO("error\n{$message}", true, $this->errorKindMeta(null, $errorMessage));
    }

    protected function getFallbackModels(): array
    {
        $configured = config('ai.engines.openrouter.models', []);
        if (!empty($configured)) {
            return $configured;
        }

        return [
            'openrouter/auto' => [
                'id'           => 'openrouter/auto',
                'display_name' => 'OpenRouter Auto',
                'owned_by'     => 'openrouter',
                'recommended'  => true,
                'source'       => 'fallback',
            ],
        ];
    }

    protected function getFallbackModelOptions(): array
    {
        $options = [];
        foreach ($this->getFallbackModels() as $id => $info) {
            $options[$id] = (!empty($info['recommended']) ? '⭐ ' : '') . ($info['display_name'] ?? $id);
        }
        return $options;
    }

    protected function formatModelDisplayName(string $modelId): string
    {
        return ucwords(str_replace(['/', '-', '_'], [' / ', ' ', ' '], $modelId));
    }

    /**
     * DepthNet's trusted vendors plus the ones users ask for.
     */
    protected function isRecommendedModel(string $modelId): bool
    {
        foreach (config('ai.engines.openrouter.recommended_vendors', ['anthropic/', 'deepseek/', 'qwen/', 'x-ai/']) as $prefix) {
            if (str_starts_with($modelId, $prefix)) {
                return true;
            }
        }
        return false;
    }
}

<?php

namespace App\Services\Agent\Providers;

use App\Contracts\Agent\AiModelRequestInterface;
use App\Contracts\Agent\AiModelResponseInterface;
use App\Contracts\Agent\Models\AIModelEngineInterface;
use App\Exceptions\AiModelException;
use App\Services\Agent\DTO\ModelResponseDTO;
use App\Services\Agent\Providers\Traits\AiModelPromptTrait;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * Universal engine for local AI models (LLaMA, Phi, Mistral, etc.)
 * Works with OpenAI-compatible API servers like Ollama, LM Studio, text-generation-webui
 */
class LocalModel implements AIModelEngineInterface
{
    use AiModelPromptTrait;

    protected ?string $serverUrl = null;

    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
        protected CacheManager $cache,
        protected array $config = []
    ) {
        // Get default config from global AI config
        $defaultConfig = config('ai.engines.local', []);

        $this->config = array_merge($this->getDefaultConfig(), $defaultConfig, $config);

        // Set server URL from config if not provided
        $this->serverUrl = $this->config['server_url'];
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
        return config('ai.engines.local.display_name', 'Local models');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return config(
            'ai.engines.local.description',
            'Universal engine for local AI models (LLaMA, Phi, Mistral, Code Llama, etc.). Works with OpenAI-compatible servers: Ollama, LM Studio, text-generation-webui, KoboldCPP.'
        );
    }

    /**
     * @inheritDoc
     */
    public function supportsDynamicModels(): bool
    {
        return false;
    }

    /**
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
        $serverTypes = config('ai.engines.local.server_types', []);
        $modelFamilies = config('ai.engines.local.model_families', []);
        $validation = config('ai.engines.local.validation', []);

        // Build server type options
        $serverOptions = [];
        foreach ($serverTypes as $type => $info) {
            $serverOptions[$type] = $info['display_name'] ?? ucfirst($type);
        }

        // Build model family options
        $familyOptions = [];
        foreach ($modelFamilies as $family => $info) {
            $familyOptions[$family] = $info['display_name'] ?? ucfirst($family);
        }

        return [
            'api_key' => [
                'type' => 'password',
                'label' => 'API Key',
                'description' => 'Optional API key for authentication (not used for local models)',
                'placeholder' => '...',
                'required' => false
            ],
            'server_url' => [
                'type' => 'url',
                'label' => 'Server URL',
                'description' => 'Local server address with model',
                'placeholder' => $this->config['server_url'] ?? 'http://localhost:11434',
                'required' => true
            ],
            'server_type' => [
                'type' => 'select',
                'label' => 'Server type',
                'description' => 'Program for running local models',
                'options' => $serverOptions,
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
                'options' => $familyOptions,
                'required' => false
            ],
            'temperature' => [
                'type' => 'number',
                'label' => 'Temperature',
                'description' => 'Creativity of responses (0 = deterministic, 2 = very creative)',
                'min' => $validation['temperature']['min'] ?? 0,
                'max' => $validation['temperature']['max'] ?? 2,
                'step' => 0.1,
                'required' => false
            ],
            'max_tokens' => [
                'type' => 'number',
                'label' => 'Max tokens',
                'description' => 'Maximum number of tokens in response',
                'min' => $validation['max_tokens']['min'] ?? 1,
                'max' => $validation['max_tokens']['max'] ?? 32768,
                'required' => false
            ],
            'top_p' => [
                'type' => 'number',
                'label' => 'Top P (nucleus sampling)',
                'description' => 'Alternative to temperature for randomness control',
                'min' => $validation['top_p']['min'] ?? 0,
                'max' => $validation['top_p']['max'] ?? 1,
                'step' => 0.05,
                'required' => false
            ],
            'top_k' => [
                'type' => 'number',
                'label' => 'Top K',
                'description' => 'Number of most likely tokens to select',
                'min' => $validation['top_k']['min'] ?? 1,
                'max' => $validation['top_k']['max'] ?? 200,
                'required' => false
            ],
            'repeat_penalty' => [
                'type' => 'number',
                'label' => 'Penalty for repetition',
                'description' => 'Penalty for token repetitions (>1 = less repetitions)',
                'min' => $validation['repeat_penalty']['min'] ?? 0.1,
                'max' => $validation['repeat_penalty']['max'] ?? 2.0,
                'step' => 0.05,
                'required' => false
            ],
            'timeout' => [
                'type' => 'number',
                'label' => 'Timeout (seconds)',
                'description' => 'Maximum time to wait for a response from the server',
                'min' => $validation['timeout']['min'] ?? 5,
                'max' => $validation['timeout']['max'] ?? 600,
                'required' => false
            ],
            'cleanup_enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Output Clearing',
                'description' => 'Remove service tokens and formatting from model response',
                'required' => false
            ],
            'agent_results_role' => [
                'type' => 'select',
                'label' => 'Role for Agent Results in context',
                'description' => 'Select role for Agent Results in context (default is "system")',
                'options' => [
                    'system' => 'system',
                    'assistant' => 'assistant',
                    'user' => 'user',
                    'tool' => 'tool'
                ],
                'required' => true
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
        // Get presets from config or use fallback
        $configPresets = config('ai.engines.local.recommended_presets', []);

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
        ];
    }

    /**
     * @inheritDoc
     */
    public function getDefaultConfig(): array
    {
        return [
            'api_key' => null, // Not used for local models
            'model' => config('ai.engines.local.model', 'llama3'),
            'model_family' => config('ai.engines.local.model_family', 'llama'),
            'server_type' => config('ai.engines.local.server_type', 'ollama'),
            'temperature' => (float) config('ai.engines.local.temperature', 0.8),
            'max_tokens' => (int) config('ai.engines.local.max_tokens', 2048),
            'top_p' => (float) config('ai.engines.local.top_p', 0.9),
            'top_k' => (int) config('ai.engines.local.top_k', 40),
            'repeat_penalty' => (float) config('ai.engines.local.repeat_penalty', 1.1),
            'timeout' => (int) config('ai.engines.local.timeout', 60),
            'cleanup_enabled' => config('ai.engines.local.cleanup_enabled', true),
            'server_url' => config('ai.engines.local.server_url', 'http://localhost:11434'),
            'agent_results_role' => config('ai.engines.local.agent_results_role', 'assistant'),
            'system_prompt' => config('ai.engines.local.system_prompt', 'You are a useful AI assistant.')
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

        // Get validation rules from config
        $validation = config('ai.engines.local.validation', []);

        // Validate numeric parameters using config ranges
        $numericFields = ['temperature', 'top_p', 'top_k', 'repeat_penalty', 'max_tokens', 'timeout'];

        foreach ($numericFields as $field) {
            if (isset($config[$field]) && isset($validation[$field])) {
                $value = is_numeric($config[$field]) ? (float) $config[$field] : null;
                $min = $validation[$field]['min'] ?? null;
                $max = $validation[$field]['max'] ?? null;

                if ($value !== null && $min !== null && $max !== null) {
                    if ($value < $min || $value > $max) {
                        $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . " must be between {$min} and {$max}";
                    }
                }
            }
        }

        // Validate model_family against supported families from config
        if (isset($config['model_family'])) {
            $supportedFamilies = array_keys(config('ai.engines.local.model_families', []));
            if (!empty($supportedFamilies) && !in_array($config['model_family'], $supportedFamilies)) {
                $errors['model_family'] = 'Unsupported model family. Supported: ' . implode(', ', $supportedFamilies);
            }
        }

        // Validate server_type against supported servers from config
        if (isset($config['server_type'])) {
            $supportedServers = array_keys(config('ai.engines.local.server_types', []));
            if (!empty($supportedServers) && !in_array($config['server_type'], $supportedServers)) {
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
            $endpoint = $this->getModelsEndpoint();
            $timeout = config('ai.engines.local.timeout', 10);

            $httpRequest = $this->http->timeout($timeout);

            if (!empty($this->config['api_key'])) {
                $httpRequest = $httpRequest->withHeaders([
                    'Authorization' => 'Bearer ' . $this->config['api_key']
                ]);
            }

            $response = $httpRequest->get($this->serverUrl . $endpoint);

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
            $endpoint = $this->getModelsEndpoint();
            $timeout = config('ai.engines.local.timeout', 10);

            $httpRequest = $this->http->timeout($timeout);

            if (!empty($this->config['api_key'])) {
                $httpRequest = $httpRequest->withHeaders([
                    'Authorization' => 'Bearer ' . $this->config['api_key']
                ]);
            }

            $response = $httpRequest->get($this->serverUrl . $endpoint);

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
     * Generate an AI response, optionally with tool_calls support.
     *
     * Tool_calls are only enabled when the preset config explicitly sets
     * 'supports_tool_calls' => true AND the Agent attaches tool schemas
     * via ModelRequestDTO::additionalParams['tools'].
     *
     * Two response paths when tool_calls are enabled:
     * - finish_reason='tool_calls': model wants to invoke plugins.
     *   tool_calls serialized as JSON for ToolCallParser.
     * - finish_reason='stop': normal text response.
     *
     * @param  AiModelRequestInterface  $request
     * @return AiModelResponseInterface
     */
    public function generate(AiModelRequestInterface $request): AiModelResponseInterface
    {
        try {
            $messages = $this->buildMessages($request);

            $data = array_merge($this->config, [
                'messages' => $messages,
                'stream'   => false,
            ]);

            // Remove non-API parameters that should not be sent to the model
            $nonApiParams = [
                'model_family', 'server_type', 'cleanup_enabled',
                'system_prompt', 'server_url', 'api_key', 'supports_tool_calls',
            ];
            foreach ($nonApiParams as $param) {
                unset($data[$param]);
            }

            // Attach tool schemas only if the local server explicitly supports tool_calls.
            // Not all local models/servers implement this — opt-in via preset config.
            // tool_choice='auto' lets the model decide whether to call a tool.
            $tools = $request->getAdditionalParam('tools', []);
            if (!empty($tools) && ($this->config['supports_tool_calls'] ?? false)) {
                $data['tools']       = $tools;
                $data['tool_choice'] = 'auto';
            }

            $endpoint = $this->getApiEndpoint();
            $timeout  = $this->config['timeout'] ?? config('ai.engines.local.timeout', 60);

            $httpRequest = $this->http->timeout($timeout);

            if (!empty($this->config['api_key'])) {
                $httpRequest = $httpRequest->withHeaders([
                    'Authorization' => 'Bearer ' . $this->config['api_key'],
                ]);
            }

            $response = $httpRequest->post($this->serverUrl . $endpoint, $data);

            if ($response->failed()) {
                $errorMessage = config('ai.global.error_messages.connection_failed', 'HTTP Error');
                throw new AiModelException("{$errorMessage}: {$response->status()} - {$response->body()}");
            }

            $result       = $response->json();
            $choice       = $result['choices'][0] ?? null;
            $finishReason = $choice['finish_reason'] ?? null;
            $message      = $choice['message'] ?? null;

            // Tool-calls path: model chose to invoke one or more plugins.
            // Only reached when supports_tool_calls=true and tools were attached.
            if ($finishReason === 'tool_calls' && !empty($message['tool_calls'])) {
                $this->logger->info('LocalModel tool_calls received', [
                    'model' => $this->config['model'] ?? 'unknown',
                    'count' => count($message['tool_calls']),
                ]);

                return new ModelResponseDTO(
                    json_encode(['tool_calls' => $message['tool_calls']])
                );
            }

            // Normal text response path
            $content = $message['content'] ?? null;

            if ($content === null) {
                $this->logger->warning('Invalid local model response format', [
                    'response'   => $result,
                    'model'      => $this->config['model'] ?? 'unknown',
                    'server_url' => $this->serverUrl,
                ]);

                throw new AiModelException(
                    config('ai.global.error_messages.invalid_format', 'Invalid response format from local model')
                );
            }

            return new ModelResponseDTO(
                ($this->config['cleanup_enabled'] ?? true) ? $this->cleanOutput($content) : $content
            );

        } catch (\Exception $e) {
            $this->logger->error('LocalModel error: ' . $e->getMessage(), [
                'trace'        => $e->getTraceAsString(),
                'context_size' => count($request->getContext()),
                'config'       => $this->config,
                'server_url'   => $this->serverUrl,
            ]);

            return new ModelResponseDTO(
                'error\nError generating response: ' . $e->getMessage(),
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

        // Update server URL if provided
        if (isset($newConfig['server_url'])) {
            $this->serverUrl = $newConfig['server_url'];
        }
    }

    /**
     * @inheritDoc
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Build messages array for the local model API.
     *
     * Local models are OpenAI-compatible — system prompt goes as first message.
     *
     * Role mapping:
     *   user    → role: user
     *   command → role: assistant + tool_calls (if tool_calls_raw in metadata)
     *             role: assistant (plain, for tag-mode command messages)
     *   result  → role: tool + tool_call_id per entry (if tool_results in metadata)
     *             role: agent_results_role             (plain, tag-mode fallback)
     *   default → role: assistant
     *
     * Note: tool_calls history entries are only present when the preset has
     * supports_tool_calls=true and previously produced tool_calls responses.
     * All other presets go through the plain fallback paths unchanged.
     *
     * @param  AiModelRequestInterface $request
     * @return array                   Messages array ready for the local model API
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
                    if ($toolCallsRaw) {
                        $decoded    = json_decode($toolCallsRaw, true);
                        $toolCalls  = $decoded['tool_calls'] ?? [];

                        if (!empty($toolCalls)) {
                            // Emit null content — OpenAI spec requires content=null
                            // when tool_calls are present in the assistant turn
                            $messages[] = [
                                'role'       => 'assistant',
                                'content'    => null,
                                'tool_calls' => $toolCalls,
                            ];
                            break;
                        }
                    }

                    // tag-mode fallback: plain assistant message
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

    public function getAvailableModels(?array $config = null): array
    {
        return [];
    }

    /**
     * Clean output using config patterns
     */
    protected function cleanOutput(?string $output): string
    {
        if (empty($output)) {
            $errorMessage = config('ai.global.error_messages.empty_response', 'Error: Local model did not respond. Check your server settings.');
            return "response_from_model\n{$errorMessage}";
        }

        $cleanOutput = $output;

        // Apply general cleanup patterns from config
        $ansiPattern = config('ai.engines.local.cleanup.ansi_escape', '/\x1b\[[0-9;]*m/');
        $cleanOutput = preg_replace($ansiPattern, '', $cleanOutput);

        // Apply model family specific cleanup patterns
        $modelFamily = $this->config['model_family'] ?? 'other';
        $familyPatterns = config("ai.engines.local.model_families.{$modelFamily}.cleanup_patterns", []);

        foreach ($familyPatterns as $pattern) {
            $cleanOutput = preg_replace($pattern, '', $cleanOutput);
        }

        // Apply role prefixes cleanup
        $rolePrefixPattern = config('ai.engines.local.cleanup.role_prefixes', '/^(assistant|user|system|AI):\s*/i');
        $cleanOutput = preg_replace($rolePrefixPattern, '', $cleanOutput);

        $cleanOutput = trim($cleanOutput);

        if (empty($cleanOutput)) {
            $errorMessage = config('ai.global.error_messages.empty_response', 'Error: Local model returned an empty response.');
            return "response_from_model\n{$errorMessage}";
        }

        return $cleanOutput;
    }

    /**
     * Get API endpoint based on server type from config
     */
    protected function getApiEndpoint(): string
    {
        $serverType = $this->config['server_type'] ?? 'ollama';
        $endpoint = config("ai.engines.local.server_types.{$serverType}.endpoint", '/v1/chat/completions');

        return $endpoint;
    }

    /**
     * Get models endpoint for connection testing
     */
    protected function getModelsEndpoint(): string
    {
        $serverType = $this->config['server_type'] ?? 'ollama';
        $endpoint = config("ai.engines.local.server_types.{$serverType}.models_endpoint", '/v1/models');

        return $endpoint;
    }

    /**
     * Set optimized settings for different modes using config
     */
    public function setMode(string $mode): void
    {
        // Get mode presets from config
        $modePresets = config('ai.engines.local.mode_presets', []);

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

    /**
     * Get information about current model family
     */
    public function getModelFamilyInfo(): array
    {
        $modelFamily = $this->config['model_family'] ?? 'other';
        return config("ai.engines.local.model_families.{$modelFamily}", []);
    }

    /**
     * Get current server type information
     */
    public function getServerTypeInfo(): array
    {
        $serverType = $this->config['server_type'] ?? 'ollama';
        return config("ai.engines.local.server_types.{$serverType}", []);
    }
}

<?php

namespace App\Services\Agent\Capabilities\Vision\Drivers;

use App\Contracts\Agent\Capabilities\ListsModelsInterface;
use App\Contracts\Agent\Capabilities\VisionProviderInterface;
use App\Services\Agent\Capabilities\Vision\DTO\ImageData;
use App\Services\Agent\Capabilities\Vision\DTO\VisionResult;
use App\Services\Agent\Capabilities\Vision\Traits\ProvidesNormalizationFields;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * Novita AI vision provider (VLM).
 *
 * Novita exposes an OpenAI-compatible Chat Completion (VLM) endpoint that
 * accepts the standard image_url content block — the same shape DeepSeek's
 * API nominally wants, but Novita actually serves it reliably across its
 * hosted vision-language models.
 *
 * NOTE on base_url: Novita exposes both /openai/v1 and /v3/openai as
 * OpenAI-compatible bases. We use /v3/openai to match NovitaEmbeddingProvider
 * (one mental model across capabilities). Chat → {base}/chat/completions,
 * model list → {base}/models.
 *
 * NOTE on model: Novita's VLM catalog is large and changes over time, so the
 * model field is FREE TEXT, not a fixed select. Paste the exact model ID from
 * Novita's model library (https://novita.ai/models) — e.g. a Qwen-VL or
 * similar VLM identifier. This keeps the provider from going stale when the
 * catalog shifts.
 *
 * Config keys (stored in preset_capability_configs.config):
 *   api_key   — Novita API key (nvapi-...)
 *   base_url  — API base (default https://api.novita.ai/v3/openai)
 *   model     — exact VLM model ID from Novita's catalog
 *   prompt    — default instruction used when no question is supplied
 */
class NovitaVisionProvider implements VisionProviderInterface, ListsModelsInterface
{
    use ProvidesNormalizationFields;

    private const DEFAULT_PROMPT = 'Describe what is shown in this picture in detail and to the point.';

    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private string $defaultPrompt;

    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
        array $config = [],
    ) {
        $this->apiKey  = $config['api_key'] ?? '';
        $this->baseUrl = rtrim(
            $config['base_url'] ?? 'https://api.novita.ai/v3/openai',
            '/'
        );
        $this->model         = $config['model']  ?? '';
        $this->defaultPrompt = $config['prompt'] ?? self::DEFAULT_PROMPT;
    }

    // ── CapabilityProviderInterface ──────────────────────────────────────────

    public function getDriverName(): string
    {
        return 'novita';
    }

    public function getDisplayName(): string
    {
        return 'Novita AI Vision';
    }

    public function getConfigFields(): array
    {
        return array_merge([
            'api_key' => [
                'type'        => 'password',
                'label'       => 'API Key',
                'description' => 'Your Novita AI API key (nvapi-...)',
                'placeholder' => 'nvapi-...',
                'required'    => true,
            ],
            'base_url' => [
                'type'        => 'url',
                'label'       => 'Base URL',
                'description' => 'Novita OpenAI-compatible base (same as embeddings).',
                'placeholder' => 'https://api.novita.ai/v3/openai',
                'required'    => false,
            ],
            'model' => [
                'type'        => 'text',
                'label'       => 'VLM Model ID',
                'description' => 'Exact vision model ID from Novita catalog (novita.ai/models). Must be a VLM.',
                'placeholder' => 'e.g. qwen/qwen2.5-vl-72b-instruct',
                'required'    => true,
            ],
            'prompt' => [
                'type'        => 'text',
                'label'       => 'Default prompt',
                'description' => 'Used when no specific question is asked (e.g. bare [see][/see]).',
                'placeholder' => self::DEFAULT_PROMPT,
                'required'    => false,
            ],
            'send_to_pool' => [
                'type'        => 'checkbox',
                'label'       => 'Route recognized media to input pool',
                'description' => 'When ON and the preset uses input-pool mode, descriptions of '
                    . 'images returned by MCP tools are pushed into the input pool (and, if '
                    . 'configured as a known source, into the prompt as a sensory input) instead '
                    . 'of appearing as a plain tool result.',
                'required'    => false,
            ],
        ], $this->normalizationFields());
    }

    public function getDefaultConfig(): array
    {
        return array_merge([
            'base_url' => 'https://api.novita.ai/v3/openai',
            'prompt'   => self::DEFAULT_PROMPT,
            'send_to_pool' => false,
        ], $this->normalizationDefaults());
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (empty($config['api_key'])) {
            $errors['api_key'] = 'API key is required.';
        }

        if (empty($config['model'])) {
            $errors['model'] = 'Model ID is required. Paste an exact VLM id from Novita catalog.';
        }

        if (!empty($config['base_url']) && !filter_var($config['base_url'], FILTER_VALIDATE_URL)) {
            $errors['base_url'] = 'Base URL must be a valid URL.';
        }

        return $errors;
    }

    // ── VisionProviderInterface ──────────────────────────────────────────────

    public function describeResult(ImageData $image, ?string $query = null): VisionResult
    {
        if (empty($this->apiKey)) {
            return VisionResult::fail('Novita: API key is not set.');
        }
        if (empty($this->model)) {
            return VisionResult::fail('Novita: VLM model id is not set.');
        }

        $prompt = ($query !== null && trim($query) !== '') ? trim($query) : $this->defaultPrompt;

        try {
            $response = $this->http
                ->withToken($this->apiKey)
                ->timeout(60)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'    => $this->model,
                    'stream'   => false,
                    'messages' => [[
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => $image->toDataUri()]],
                        ],
                    ]],
                ]);

            if ($response->failed()) {
                return VisionResult::fail($this->explainHttp(
                    'Novita',
                    $response->status(),
                    $response->body(),
                    $this->model
                ));
            }

            $content = $response->json('choices.0.message.content');

            if (!is_string($content) || trim($content) === '') {
                return VisionResult::fail('Novita: model returned an empty description (check that the model is a VLM).');
            }

            return VisionResult::ok(trim($content));

        } catch (\Throwable $e) {
            return VisionResult::fail('Novita: request failed — ' . $e->getMessage());
        }
    }

    // ── ListsModelsInterface ─────────────────────────────────────────────────

    /**
     * Fetch the OpenAI-compatible model list from {base}/models.
     *
     * Returns a flat array of ['id' => ..., 'title' => ..., 'description' => ...].
     * The VLM models are the ones usable here — Novita does not flag vision in
     * the list, so the human picks an appropriate VLM id (e.g. a Qwen-VL).
     *
     * @return array<int, array<string, string>>
     */
    public function listModels(): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        try {
            $response = $this->http
                ->withToken($this->apiKey)
                ->timeout(20)
                ->get("{$this->baseUrl}/models");

            if ($response->failed()) {
                $this->logger->error('NovitaVisionProvider: model list error.', [
                    'status' => $response->status(),
                    'body'   => mb_substr($response->body(), 0, 300),
                ]);
                return [];
            }

            $models = [];
            foreach ($response->json('data', []) as $m) {
                $id = $m['id'] ?? null;
                if ($id === null) {
                    continue;
                }
                $models[] = [
                    'id'          => $id,
                    'title'       => $m['title'] ?? $id,
                    'description' => mb_substr((string) ($m['description'] ?? ''), 0, 160),
                ];
            }

            return $models;

        } catch (\Throwable $e) {
            $this->logger->error('NovitaVisionProvider: model list failed: ' . $e->getMessage());
            return [];
        }
    }
}

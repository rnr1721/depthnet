<?php

namespace App\Services\Agent\Capabilities\Embedding\Drivers;

use App\Contracts\Agent\Capabilities\EmbeddingProviderInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * Novita AI embedding provider.
 *
 * Uses the OpenAI-compatible /v1/embeddings endpoint.
 * Recommended models:
 *   - baai/bge-m3              (1024 dim, multilingual, good default)
 *   - nomic-ai/nomic-embed-text-v1.5 (768 dim, fast)
 *   - baai/bge-large-en-v1.5  (1024 dim, English-focused)
 *
 * Config keys (stored in preset_capability_configs.config):
 *   api_key    — Novita API key (nvapi-...)
 *   base_url   — API base URL (default from config file)
 *   model      — Embedding model ID
 */
class NovitaEmbeddingProvider implements EmbeddingProviderInterface
{
    /** Known output dimensions to avoid a probe request on first use. */
    private const KNOWN_DIMENSIONS = [
        'baai/bge-m3'                        => 1024,
        'nomic-ai/nomic-embed-text-v1.5'     => 768,
        'baai/bge-large-en-v1.5'            => 1024,
        'thenlper/gte-large'                 => 1024,
        'intfloat/e5-mistral-7b-instruct'    => 4096,
    ];

    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private ?int   $resolvedDimension = null;

    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
        array $config = [],
    ) {
        $this->apiKey  = $config['api_key'] ?? '';
        $this->baseUrl = rtrim(
            $config['base_url'] ?? config('ai.engines.novita.server_url', 'https://api.novita.ai/v3/openai'),
            '/'
        );
        $this->model   = $config['model'] ?? 'baai/bge-m3';
    }

    // -------------------------------------------------------------------------
    // CapabilityProviderInterface
    // -------------------------------------------------------------------------

    public function getDriverName(): string
    {
        return 'novita';
    }

    public function getDisplayName(): string
    {
        return 'Novita AI';
    }

    public function getConfigFields(): array
    {
        return [
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
                'description' => 'API base URL. Leave default unless self-hosting.',
                'placeholder' => 'https://api.novita.ai/v3/openai',
                'required'    => false,
            ],
            'model' => [
                'type'        => 'select',
                'label'       => 'Embedding Model',
                'description' => 'Model used to generate embedding vectors.',
                'required'    => true,
                'options'     => [
                    'baai/bge-m3'                        => 'BGE-M3 (1024 dim, multilingual — recommended)',
                    'nomic-ai/nomic-embed-text-v1.5'     => 'Nomic Embed v1.5 (768 dim, fast)',
                    'baai/bge-large-en-v1.5'            => 'BGE Large EN v1.5 (1024 dim, English)',
                    'thenlper/gte-large'                 => 'GTE Large (1024 dim)',
                    'intfloat/e5-mistral-7b-instruct'    => 'E5 Mistral 7B (4096 dim, high quality)',
                ],
            ],
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'base_url' => 'https://api.novita.ai/v3/openai',
            'model'    => 'baai/bge-m3',
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (empty($config['api_key'])) {
            $errors['api_key'] = 'API key is required.';
        }

        if (!empty($config['base_url']) && !filter_var($config['base_url'], FILTER_VALIDATE_URL)) {
            $errors['base_url'] = 'Base URL must be a valid URL.';
        }

        return $errors;
    }

    // -------------------------------------------------------------------------
    // EmbeddingProviderInterface
    // -------------------------------------------------------------------------

    public function embed(string $text): ?array
    {
        $results = $this->request([$text]);
        return $results[0] ?? null;
    }

    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        return $this->request(array_values($texts));
    }

    public function getDimension(): int
    {
        if ($this->resolvedDimension !== null) {
            return $this->resolvedDimension;
        }

        if (isset(self::KNOWN_DIMENSIONS[$this->model])) {
            $this->resolvedDimension = self::KNOWN_DIMENSIONS[$this->model];
            return $this->resolvedDimension;
        }

        // Unknown model — probe with a test request
        $vector = $this->embed('dimension probe');
        $this->resolvedDimension = $vector ? count($vector) : 1024;

        return $this->resolvedDimension;
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Send request to /v1/embeddings and return indexed float[][] results.
     *
     * @param  string[]  $inputs
     * @return array<int, float[]|null>
     */
    private function request(array $inputs): array
    {
        if (empty($this->apiKey)) {
            $this->logger->warning('NovitaEmbeddingProvider: api_key is empty.');
            return array_fill(0, count($inputs), null);
        }

        try {
            $response = $this->http
                ->withToken($this->apiKey)
                ->timeout(20)
                ->post("{$this->baseUrl}/v1/embeddings", [
                    'model' => $this->model,
                    'input' => count($inputs) === 1 ? $inputs[0] : $inputs,
                ]);

            if ($response->failed()) {
                $this->logger->error('NovitaEmbeddingProvider: API error.', [
                    'status' => $response->status(),
                    'body'   => mb_substr($response->body(), 0, 500),
                    'model'  => $this->model,
                ]);
                return array_fill(0, count($inputs), null);
            }

            $data    = $response->json();
            $results = array_fill(0, count($inputs), null);

            foreach ($data['data'] ?? [] as $item) {
                $index  = $item['index'] ?? 0;
                $vector = $item['embedding'] ?? null;

                if (is_array($vector) && !empty($vector)) {
                    $results[$index] = array_map('floatval', $vector);

                    if ($this->resolvedDimension === null) {
                        $this->resolvedDimension = count($results[$index]);
                    }
                }
            }

            return $results;

        } catch (\Throwable $e) {
            $this->logger->error('NovitaEmbeddingProvider: request failed: ' . $e->getMessage(), [
                'model' => $this->model,
            ]);
            return array_fill(0, count($inputs), null);
        }
    }
}

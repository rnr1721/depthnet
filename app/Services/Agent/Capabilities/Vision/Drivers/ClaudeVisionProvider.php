<?php

namespace App\Services\Agent\Capabilities\Vision\Drivers;

use App\Contracts\Agent\Capabilities\VisionProviderInterface;
use App\Services\Agent\Capabilities\Vision\DTO\ImageData;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * Claude (Anthropic) vision provider.
 *
 * All current Claude models accept image input. We send a single user turn
 * with a native Anthropic image block (base64, NO data: prefix) plus a text
 * block carrying the question.
 *
 * Narrow call — does NOT go through ClaudeModel (the conversational engine).
 *
 * Auth/format details mirror ClaudeModel:
 *   - endpoint:  https://api.anthropic.com/v1/messages
 *   - headers:   x-api-key + anthropic-version (NOT Bearer)
 *   - image:     {type:image, source:{type:base64, media_type, data}}
 *
 * Config keys (stored in preset_capability_configs.config):
 *   api_key      — Anthropic key (sk-ant-...)
 *   base_url     — messages endpoint (default https://api.anthropic.com/v1/messages)
 *   model        — claude-sonnet-4-6 (default), claude-opus-4-8, claude-haiku-4-5
 *   max_tokens   — output cap for the description (default 1024)
 *   prompt       — default instruction when no question is supplied
 */
class ClaudeVisionProvider implements VisionProviderInterface
{
    private const DEFAULT_PROMPT  = 'Опиши, что изображено на этом снимке, подробно и по существу.';
    private const ANTHROPIC_VERSION = '2023-06-01';

    /** Anthropic accepts these media types for image blocks. */
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private int    $maxTokens;
    private string $defaultPrompt;

    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
        array $config = [],
    ) {
        $this->apiKey  = $config['api_key'] ?? '';
        $this->baseUrl = rtrim(
            $config['base_url'] ?? 'https://api.anthropic.com/v1/messages',
            '/'
        );
        $this->model         = $config['model']      ?? 'claude-sonnet-4-6';
        $this->maxTokens     = (int) ($config['max_tokens'] ?? 1024);
        $this->defaultPrompt = $config['prompt']     ?? self::DEFAULT_PROMPT;
    }

    // ── CapabilityProviderInterface ──────────────────────────────────────────

    public function getDriverName(): string
    {
        return 'claude';
    }

    public function getDisplayName(): string
    {
        return 'Claude Vision (Anthropic)';
    }

    public function getConfigFields(): array
    {
        return [
            'api_key' => [
                'type'        => 'password',
                'label'       => 'API Key',
                'description' => 'Anthropic API key (sk-ant-...)',
                'placeholder' => 'sk-ant-api03-...',
                'required'    => true,
            ],
            'base_url' => [
                'type'        => 'url',
                'label'       => 'Messages endpoint',
                'description' => 'Anthropic Messages API URL. Leave default unless proxying.',
                'placeholder' => 'https://api.anthropic.com/v1/messages',
                'required'    => false,
            ],
            'model' => [
                'type'        => 'select',
                'label'       => 'Vision Model',
                'description' => 'Claude model used to describe images. All current models support vision.',
                'required'    => true,
                'options'     => [
                    'claude-sonnet-4-6' => 'Claude Sonnet 4.6 (balanced — recommended)',
                    'claude-opus-4-8'   => 'Claude Opus 4.8 (highest quality)',
                    'claude-haiku-4-5'  => 'Claude Haiku 4.5 (fast, cheap)',
                ],
            ],
            'max_tokens' => [
                'type'        => 'number',
                'label'       => 'Max tokens',
                'description' => 'Maximum length of the description.',
                'min'         => 64,
                'max'         => 8192,
                'required'    => false,
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
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'base_url'   => 'https://api.anthropic.com/v1/messages',
            'model'      => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'prompt'     => self::DEFAULT_PROMPT,
            'send_to_pool' => false,
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

    // ── VisionProviderInterface ──────────────────────────────────────────────

    public function describe(ImageData $image, ?string $query = null): ?string
    {
        if (empty($this->apiKey)) {
            $this->logger->warning('ClaudeVisionProvider: api_key is empty.');
            return null;
        }

        $prompt    = ($query !== null && trim($query) !== '') ? trim($query) : $this->defaultPrompt;
        $mediaType = in_array($image->getMimeType(), self::ALLOWED_MIMES, true)
            ? $image->getMimeType()
            : 'image/jpeg';

        try {
            $response = $this->http
                ->withHeaders([
                    'Content-Type'      => 'application/json',
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                ])
                ->timeout(60)
                ->post($this->baseUrl, [
                    'model'      => $this->model,
                    'max_tokens' => $this->maxTokens,
                    'messages'   => [
                        [
                            'role'    => 'user',
                            'content' => [
                                [
                                    'type'   => 'image',
                                    'source' => [
                                        'type'       => 'base64',
                                        'media_type' => $mediaType,
                                        'data'       => $image->getBase64(),
                                    ],
                                ],
                                ['type' => 'text', 'text' => $prompt],
                            ],
                        ],
                    ],
                ]);

            if ($response->failed()) {
                $this->logger->error('ClaudeVisionProvider: API error.', [
                    'status' => $response->status(),
                    'body'   => mb_substr($response->body(), 0, 500),
                    'model'  => $this->model,
                ]);
                return null;
            }

            // Anthropic returns content[] of typed blocks; collect text blocks.
            $blocks = $response->json('content', []);
            $text   = '';
            foreach ($blocks as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= $block['text'] ?? '';
                }
            }

            return trim($text) !== '' ? trim($text) : null;

        } catch (\Throwable $e) {
            $this->logger->error('ClaudeVisionProvider: request failed: ' . $e->getMessage(), [
                'model' => $this->model,
            ]);
            return null;
        }
    }
}

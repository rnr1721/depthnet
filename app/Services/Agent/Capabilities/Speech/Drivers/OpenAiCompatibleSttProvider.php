<?php

namespace App\Services\Agent\Capabilities\Speech\Drivers;

use App\Contracts\Agent\Capabilities\ListsModelsInterface;
use App\Contracts\Agent\Capabilities\SttProviderInterface;
use App\Contracts\Agent\Capabilities\TranscribesAudioInterface;
use App\Services\Agent\Capabilities\Speech\DTO\AudioData;
use App\Services\Agent\Capabilities\Speech\DTO\SttResult;
use App\Services\Agent\Capabilities\Speech\Traits\ProvidesWakeWordFields;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * OpenAI-compatible speech-to-text provider.
 *
 * ONE class, three deployments — the whole point of shaping the local container
 * after the OpenAI audio API:
 *
 *   http://voice-service:3002/v1   local faster-whisper (DepthNet default)
 *   https://api.openai.com/v1      OpenAI Whisper
 *   http://localhost:9000/v1       any other compatible server
 *
 * Endpoint: POST {base_url}/audio/transcriptions, multipart/form-data.
 * The audio part's FILENAME determines how the server decodes it, which is why
 * AudioData::suggestedFilename() maps mime types to extensions rather than
 * always sending "audio".
 */
class OpenAiCompatibleSttProvider implements
    SttProviderInterface,
    TranscribesAudioInterface,
    ListsModelsInterface
{
    use ProvidesWakeWordFields;

    private const DEFAULT_BASE_URL = 'http://voice-service:3002/v1';

    private string $baseUrl;
    private string $apiKey;
    private string $model;
    private string $language;
    private string $prompt;
    private int $timeout;
    /** @var array<string, mixed> */
    private array $raw;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
        array $config = [],
    ) {
        $this->raw      = $config;
        $this->baseUrl  = rtrim($config['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->apiKey   = $config['api_key']  ?? '';
        $this->model    = $config['model']    ?? 'whisper-1';
        $this->language = $config['language'] ?? '';
        $this->prompt   = $config['prompt']   ?? '';
        // Generous by default: CPU whisper runs roughly 2x realtime, so a long
        // voice message legitimately takes a while.
        $this->timeout  = (int) ($config['timeout'] ?? 120);
    }

    // ── CapabilityProviderInterface ──────────────────────────────────────────

    public function getDriverName(): string
    {
        return 'openai_compatible';
    }

    public function getDisplayName(): string
    {
        return 'OpenAI-compatible STT (local Whisper / OpenAI / custom)';
    }

    public function getExecutionMode(): string
    {
        return self::MODE_SERVER;
    }

    public function getConfigFields(): array
    {
        return array_merge([
            'base_url' => [
                'type'        => 'url',
                'label'       => 'API base URL',
                'description' => 'Without the /audio/transcriptions suffix. Default points '
                    . 'at the bundled voice-service container (enable it with '
                    . '`make voice-on`). Use https://api.openai.com/v1 for OpenAI.',
                'placeholder' => self::DEFAULT_BASE_URL,
                'required'    => true,
            ],
            'api_key' => [
                'type'        => 'password',
                'label'       => 'API Key',
                'description' => 'Leave empty for the local container — it needs no auth.',
                'placeholder' => 'sk-...',
                'required'    => false,
            ],
            'model' => [
                'type'        => 'text',
                'label'       => 'Model',
                'description' => 'The local container ignores this and serves whichever '
                    . 'model it was configured with. OpenAI expects whisper-1.',
                'placeholder' => 'whisper-1',
                'required'    => false,
            ],
            'language' => [
                'type'        => 'select',
                'label'       => 'Recognition language',
                'description' => 'Strongly recommended. Autodetect is unreliable on short '
                    . 'utterances — "да", "da" and "ja" sound nearly identical.',
                'required'    => false,
                'options'     => [
                    ''   => 'Autodetect (not recommended)',
                    'en' => 'English',
                    'ru' => 'Russian',
                    'fr' => 'French',
                    'de' => 'German',
                    'es' => 'Spanish',
                ],
            ],
            'prompt' => [
                'type'        => 'text',
                'label'       => 'Biasing prompt',
                'description' => 'Optional. Names and jargon the recognizer should expect, '
                    . 'e.g. the agent name and project terms.',
                'required'    => false,
            ],
            'timeout' => [
                'type'        => 'number',
                'label'       => 'Timeout (seconds)',
                'description' => 'CPU transcription runs about 2x faster than realtime, so '
                    . 'allow room for long recordings.',
                'min'         => 10,
                'max'         => 600,
                'required'    => false,
            ],
            'send_to_pool' => [
                'type'        => 'checkbox',
                'label'       => 'Route recognized speech to input pool',
                'description' => 'When ON and the preset uses input-pool mode, recognized '
                    . 'speech enters the pool as a sensory input instead of being '
                    . 'posted as a plain user message.',
                'required'    => false,
            ],
        ], $this->wakeWordFields());
    }

    public function getDefaultConfig(): array
    {
        return array_merge([
            'base_url'     => self::DEFAULT_BASE_URL,
            'api_key'      => '',
            'model'        => 'whisper-1',
            'language'     => '',
            'prompt'       => '',
            'timeout'      => 120,
            'send_to_pool' => false,
        ], $this->wakeWordDefaults());
    }

    public function validateConfig(array $config): array
    {
        $errors = $this->validateWakeWordConfig($config);

        if (empty($config['base_url'])) {
            $errors['base_url'] = 'Base URL is required.';
        } elseif (!filter_var($config['base_url'], FILTER_VALIDATE_URL)) {
            $errors['base_url'] = 'Base URL must be a valid URL.';
        }

        // A frequent copy-paste mistake: pasting the full endpoint here means
        // requests go to /audio/transcriptions/audio/transcriptions.
        if (!empty($config['base_url']) && str_contains($config['base_url'], '/audio/')) {
            $errors['base_url'] = 'Enter the base URL only, without /audio/transcriptions.';
        }

        if (isset($config['timeout']) && $config['timeout'] !== '') {
            $t = (int) $config['timeout'];
            if ($t < 10 || $t > 600) {
                $errors['timeout'] = 'Timeout must be between 10 and 600 seconds.';
            }
        }

        return $errors;
    }

    public function getClientConfig(): array
    {
        // No api_key, no base_url — the browser has no business knowing either.
        return array_merge([
            'execution_mode' => self::MODE_SERVER,
            'driver'         => $this->getDriverName(),
            'language'       => $this->language,
        ], $this->wakeWordClientConfig($this->raw));
    }

    // ── TranscribesAudioInterface ────────────────────────────────────────────

    public function transcribe(
        AudioData $audio,
        ?string $language = null,
        ?string $prompt = null,
    ): SttResult {
        if ($audio->isEmpty()) {
            return SttResult::fail('STT: empty audio payload.');
        }

        $lang   = $language ?: ($this->language ?: null);
        $hint   = $prompt   ?: ($this->prompt   ?: null);

        $form = [
            ['name' => 'model',           'contents' => $this->model],
            ['name' => 'response_format', 'contents' => 'verbose_json'],
        ];
        if ($lang !== null) {
            $form[] = ['name' => 'language', 'contents' => $lang];
        }
        if ($hint !== null) {
            $form[] = ['name' => 'prompt', 'contents' => $hint];
        }

        try {
            $request = $this->http
                ->timeout($this->timeout)
                ->attach(
                    'file',
                    $audio->getBytes(),
                    $audio->suggestedFilename(),
                    ['Content-Type' => $audio->getMimeType()]
                );

            if ($this->apiKey !== '') {
                $request = $request->withToken($this->apiKey);
            }

            $response = $request->post(
                $this->baseUrl . '/audio/transcriptions',
                $this->formToArray($form)
            );

            if ($response->failed()) {
                return SttResult::fail($this->explainHttp(
                    $response->status(),
                    $response->body()
                ));
            }

            $data = $response->json();

            // verbose_json gives text + language + duration; plain json only text.
            $text = is_array($data) ? ($data['text'] ?? '') : (string) $response->body();
            $text = trim((string) $text);

            if ($text === '') {
                return SttResult::fail(
                    'STT: nothing recognized — the recording may be silent or too short.'
                );
            }

            return SttResult::ok(
                $text,
                is_array($data) ? ($data['language'] ?? $lang) : $lang,
                is_array($data) && isset($data['duration']) ? (float) $data['duration'] : null,
            );

        } catch (\Throwable $e) {
            $this->logger->warning('STT request failed: ' . $e->getMessage(), [
                'base_url' => $this->baseUrl,
            ]);
            return SttResult::fail('STT: request failed — ' . $e->getMessage());
        }
    }

    // ── ListsModelsInterface ─────────────────────────────────────────────────

    public function listModels(): array
    {
        try {
            $request = $this->http->timeout(15);
            if ($this->apiKey !== '') {
                $request = $request->withToken($this->apiKey);
            }

            $response = $request->get($this->baseUrl . '/models');
            if ($response->failed()) {
                return [];
            }

            $data = $response->json();
            // Local container returns {models: [...]}, OpenAI returns {data: [...]}.
            $items = $data['models'] ?? $data['data'] ?? [];

            $out = [];
            foreach ($items as $item) {
                $id = $item['id'] ?? null;
                if ($id === null) {
                    continue;
                }
                $out[] = [
                    'id'          => (string) $id,
                    'title'       => (string) ($item['title'] ?? $id),
                    'description' => (string) ($item['description'] ?? ''),
                ];
            }

            return $out;

        } catch (\Throwable $e) {
            $this->logger->debug('STT listModels failed: ' . $e->getMessage());
            return [];
        }
    }

    // ── Private ──────────────────────────────────────────────────────────────

    /**
     * Laravel's ->attach() already puts the request in multipart mode, so the
     * remaining fields go in as a plain associative array.
     *
     * @param  array<int, array{name: string, contents: string}> $form
     * @return array<string, string>
     */
    private function formToArray(array $form): array
    {
        $out = [];
        foreach ($form as $field) {
            $out[$field['name']] = $field['contents'];
        }
        return $out;
    }

    private function explainHttp(int $status, string $body): string
    {
        $detail = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $detail = $decoded['detail']
                ?? $decoded['error']['message']
                ?? $decoded['message']
                ?? '';
            if (is_array($detail)) {
                $detail = json_encode($detail);
            }
        }
        if ($detail === '') {
            $detail = mb_substr(strip_tags($body), 0, 200);
        }

        $hint = match (true) {
            $status === 401, $status === 403 => ' Check the API key.',
            $status === 404 => ' Check the base URL — it should end with /v1 and not '
                . 'include /audio/transcriptions.',
            $status === 413 => ' The recording is too large for the server limit.',
            $status >= 500  => ' The STT service may still be loading its model.',
            default => '',
        };

        return "STT API error ({$status}): {$detail}{$hint}";
    }
}

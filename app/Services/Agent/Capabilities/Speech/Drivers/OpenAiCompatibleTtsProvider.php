<?php

namespace App\Services\Agent\Capabilities\Speech\Drivers;

use App\Contracts\Agent\Capabilities\ListsVoicesInterface;
use App\Contracts\Agent\Capabilities\SynthesizesSpeechInterface;
use App\Contracts\Agent\Capabilities\TtsProviderInterface;
use App\Services\Agent\Capabilities\Speech\DTO\AudioData;
use App\Services\Agent\Capabilities\Speech\DTO\TtsResult;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * OpenAI-compatible text-to-speech provider.
 *
 * Same one-class-three-deployments idea as the STT counterpart:
 *
 *   http://voice-service:3002/v1   local piper (DepthNet default)
 *   https://api.openai.com/v1      OpenAI TTS
 *   http://localhost:5000/v1       piper-http or any compatible server
 *
 * Endpoint: POST {base_url}/audio/speech with JSON, audio bytes in the response.
 *
 * Voice names are provider-specific and NOT interchangeable: the local container
 * wants piper names (ru_RU-irina-medium), OpenAI wants its own (alloy, nova).
 * listVoices() is what keeps the GUI honest about which are actually available.
 */
class OpenAiCompatibleTtsProvider implements
    TtsProviderInterface,
    SynthesizesSpeechInterface,
    ListsVoicesInterface
{
    private const DEFAULT_BASE_URL = 'http://voice-service:3002/v1';

    /** Response formats the local container and OpenAI both understand. */
    private const FORMATS = ['wav', 'mp3', 'opus'];

    private const FORMAT_MIMES = [
        'wav'  => 'audio/wav',
        'mp3'  => 'audio/mpeg',
        'opus' => 'audio/ogg',
    ];

    private string $baseUrl;
    private string $apiKey;
    private string $model;
    private string $voice;
    private string $format;
    private float $speed;
    private int $timeout;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
        array $config = [],
    ) {
        $this->baseUrl = rtrim($config['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->apiKey  = $config['api_key'] ?? '';
        $this->model   = $config['model']   ?? 'tts-1';
        $this->voice   = $config['voice']   ?? '';
        $this->format  = $config['response_format'] ?? 'mp3';
        $this->speed   = (float) ($config['speed'] ?? 1.0);
        $this->timeout = (int) ($config['timeout'] ?? 60);
    }

    // ── CapabilityProviderInterface ──────────────────────────────────────────

    public function getDriverName(): string
    {
        return 'openai_compatible';
    }

    public function getDisplayName(): string
    {
        return 'OpenAI-compatible TTS (local Piper / OpenAI / custom)';
    }

    public function getExecutionMode(): string
    {
        return self::MODE_SERVER;
    }

    public function getConfigFields(): array
    {
        return [
            'base_url' => [
                'type'        => 'url',
                'label'       => 'API base URL',
                'description' => 'Without the /audio/speech suffix. Default points at the '
                    . 'bundled voice-service container (enable it with '
                    . '`make voice-on`).',
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
                'description' => 'Ignored by the local container. OpenAI expects tts-1 or '
                    . 'tts-1-hd.',
                'placeholder' => 'tts-1',
                'required'    => false,
            ],
            'voice' => [
                'type'        => 'text',
                'label'       => 'Voice',
                'description' => 'Provider-specific. Local Piper uses names like '
                    . 'ru_RU-irina-medium; OpenAI uses alloy, nova and so on. '
                    . 'Use "Load voices" to see what this endpoint offers.',
                'placeholder' => 'ru_RU-irina-medium',
                'required'    => false,
            ],
            'response_format' => [
                'type'        => 'select',
                'label'       => 'Audio format',
                'description' => 'mp3 is the safest for playback and messenger delivery. '
                    . 'opus is smallest; Telegram voice messages want it.',
                'required'    => false,
                'options'     => [
                    'mp3'  => 'MP3 (recommended)',
                    'opus' => 'Opus / OGG (smallest)',
                    'wav'  => 'WAV (uncompressed)',
                ],
            ],
            'speed' => [
                'type'        => 'number',
                'label'       => 'Speech rate',
                'description' => '1.0 is normal.',
                'min'         => 0.25,
                'max'         => 4.0,
                'step'        => 0.05,
                'required'    => false,
            ],
            'timeout' => [
                'type'        => 'number',
                'label'       => 'Timeout (seconds)',
                'description' => 'Piper is much faster than realtime; cloud providers vary.',
                'min'         => 5,
                'max'         => 300,
                'required'    => false,
            ],
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'base_url'        => self::DEFAULT_BASE_URL,
            'api_key'         => '',
            'model'           => 'tts-1',
            'voice'           => '',
            'response_format' => 'mp3',
            'speed'           => 1.0,
            'timeout'         => 60,
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (empty($config['base_url'])) {
            $errors['base_url'] = 'Base URL is required.';
        } elseif (!filter_var($config['base_url'], FILTER_VALIDATE_URL)) {
            $errors['base_url'] = 'Base URL must be a valid URL.';
        }

        if (!empty($config['base_url']) && str_contains($config['base_url'], '/audio/')) {
            $errors['base_url'] = 'Enter the base URL only, without /audio/speech.';
        }

        $format = $config['response_format'] ?? 'mp3';
        if (!in_array($format, self::FORMATS, true)) {
            $errors['response_format'] = 'Format must be one of: '
                . implode(', ', self::FORMATS) . '.';
        }

        if (isset($config['speed']) && $config['speed'] !== '') {
            $speed = (float) $config['speed'];
            if ($speed < 0.25 || $speed > 4.0) {
                $errors['speed'] = 'Speed must be between 0.25 and 4.0.';
            }
        }

        return $errors;
    }

    public function getClientConfig(): array
    {
        // The frontend needs to know audio arrives from the server (so it plays
        // a returned file instead of calling speechSynthesis), and nothing else.
        return [
            'execution_mode' => self::MODE_SERVER,
            'driver'         => $this->getDriverName(),
            'format'         => $this->format,
        ];
    }

    // ── SynthesizesSpeechInterface ───────────────────────────────────────────

    public function synthesize(
        string $text,
        ?string $voice = null,
        float $speed = 1.0,
    ): TtsResult {
        $text = trim($text);
        if ($text === '') {
            return TtsResult::fail('TTS: empty text.');
        }

        $payload = [
            'model'           => $this->model,
            'input'           => $text,
            'response_format' => $this->format,
            // Explicit speed beats the configured one; 1.0 means "unspecified"
            // at the call site, so fall back to config in that case.
            'speed'           => $speed !== 1.0 ? $speed : $this->speed,
        ];

        $chosenVoice = $voice ?: $this->voice;
        if ($chosenVoice !== '') {
            $payload['voice'] = $chosenVoice;
        }

        try {
            $request = $this->http->timeout($this->timeout);
            if ($this->apiKey !== '') {
                $request = $request->withToken($this->apiKey);
            }

            $response = $request->post($this->baseUrl . '/audio/speech', $payload);

            if ($response->failed()) {
                return TtsResult::fail($this->explainHttp(
                    $response->status(),
                    $response->body(),
                    $chosenVoice
                ));
            }

            $bytes = $response->body();
            if ($bytes === '') {
                return TtsResult::fail('TTS: provider returned no audio.');
            }

            // Trust the response header when present — a proxy may transcode.
            $mime = $response->header('Content-Type')
                ?: (self::FORMAT_MIMES[$this->format] ?? 'audio/mpeg');
            $mime = trim(explode(';', $mime)[0]);

            return TtsResult::ok(AudioData::fromBinary($bytes, $mime, 'tts'));

        } catch (\Throwable $e) {
            $this->logger->warning('TTS request failed: ' . $e->getMessage(), [
                'base_url' => $this->baseUrl,
            ]);
            return TtsResult::fail('TTS: request failed — ' . $e->getMessage());
        }
    }

    // ── ListsVoicesInterface ─────────────────────────────────────────────────

    public function listVoices(?string $model = null): array
    {
        try {
            $request = $this->http->timeout(15);
            if ($this->apiKey !== '') {
                $request = $request->withToken($this->apiKey);
            }

            $response = $request->get($this->baseUrl . '/voices', array_filter([
                'model' => $model ?: $this->model,
            ]));

            if ($response->failed()) {
                // OpenAI has no /voices endpoint — its voice set is fixed, so
                // fall back to the documented list rather than showing nothing.
                return $this->staticOpenAiVoices();
            }

            $data  = $response->json();
            $items = $data['voices'] ?? $data['data'] ?? [];

            $out = [];
            foreach ($items as $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : $item;
                if ($id === null) {
                    continue;
                }
                $entry = ['id' => (string) $id, 'title' => (string) $id];
                if (is_array($item)) {
                    $entry['title']    = (string) ($item['title'] ?? $id);
                    $entry['language'] = (string) ($item['language'] ?? '');
                    $entry['gender']   = (string) ($item['gender'] ?? '');
                }
                $out[] = $entry;
            }

            return $out ?: $this->staticOpenAiVoices();

        } catch (\Throwable $e) {
            $this->logger->debug('TTS listVoices failed: ' . $e->getMessage());
            return $this->staticOpenAiVoices();
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function staticOpenAiVoices(): array
    {
        if (!str_contains($this->baseUrl, 'api.openai.com')) {
            return [];
        }

        return array_map(
            static fn (string $v) => ['id' => $v, 'title' => ucfirst($v), 'language' => 'multi'],
            ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer']
        );
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function explainHttp(int $status, string $body, string $voice): string
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
            $status === 404 => $voice !== ''
                ? " Voice '{$voice}' may not be installed — use \"Load voices\" to see "
                    . 'what is available.'
                : ' Check the base URL — it should end with /v1.',
            $status >= 500 => ' The TTS service may still be starting.',
            default => '',
        };

        return "TTS API error ({$status}): {$detail}{$hint}";
    }
}

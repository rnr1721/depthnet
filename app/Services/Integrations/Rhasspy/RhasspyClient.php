<?php

namespace App\Services\Integrations\Rhasspy;

use App\Contracts\Integrations\Rhasspy\RhasspyClientInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * Low-level HTTP client for Rhasspy REST API.
 * All timeouts are short — we never block the agent cycle.
 */
class RhasspyClient implements RhasspyClientInterface
{
    private const TTS_PATH       = '/api/text-to-speech';
    private const VERSION_PATH   = '/api/version';
    private const TIMEOUT        = 5;
    private const PING_TIMEOUT   = 3;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function say(string $text, string $voice = ''): bool
    {
        try {
            $url  = rtrim($this->baseUrl, '/') . self::TTS_PATH;
            $body = $text;

            $request = $this->http
                ->timeout(self::TIMEOUT)
                ->withHeaders(['Content-Type' => 'text/plain']);

            // Rhasspy TTS accepts optional ?voice= query param
            if (!empty($voice)) {
                $url .= '?' . http_build_query(['voice' => $voice]);
            }

            $response = $request->post($url, $body);

            if (!$response->successful()) {
                $this->logger->warning('RhasspyClient: TTS request failed', [
                    'status' => $response->status(),
                    'url'    => $url,
                ]);
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            $this->logger->error('RhasspyClient: TTS exception', [
                'error' => $e->getMessage(),
                'url'   => $this->baseUrl,
            ]);
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        try {
            $url      = rtrim($this->baseUrl, '/') . self::VERSION_PATH;
            $response = $this->http->timeout(self::PING_TIMEOUT)->get($url);

            return $response->successful();

        } catch (\Throwable $e) {
            $this->logger->debug('RhasspyClient: ping failed', [
                'error' => $e->getMessage(),
                'url'   => $this->baseUrl,
            ]);
            return false;
        }
    }
}

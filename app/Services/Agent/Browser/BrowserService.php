<?php

namespace App\Services\Agent\Browser;

use App\Contracts\Agent\Browser\BrowserServiceInterface;
use App\Services\Agent\Browser\DTO\BrowserResult;
use App\Services\Agent\Browser\DTO\BrowserSnapshot;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * HTTP transport to the node Playwright browser-service.
 *
 * One instance is configured with a service URL and request timeout (the
 * plugin supplies these from the preset config). All actions funnel through
 * dispatch(), which POSTs to /action and normalises the response into a
 * BrowserResult. Network/transport problems become fail() results — the
 * service never throws at the plugin.
 */
class BrowserService implements BrowserServiceInterface
{
    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
        protected string $serviceUrl,
        protected int $timeout = 60,
    ) {
        $this->serviceUrl = rtrim($this->serviceUrl, '/');
    }

    public function open(string $sessionId, string $url): BrowserResult
    {
        return $this->dispatch($sessionId, 'open', ['url' => $url]);
    }

    public function snapshot(string $sessionId): BrowserResult
    {
        return $this->dispatch($sessionId, 'snapshot');
    }

    public function click(string $sessionId, string $target): BrowserResult
    {
        return $this->dispatch($sessionId, 'click', ['selector' => $target]);
    }

    public function type(string $sessionId, string $target, string $text, bool $submit = false): BrowserResult
    {
        return $this->dispatch($sessionId, 'type', [
            'selector' => $target,
            'text'     => $text,
            'submit'   => $submit,
        ]);
    }

    public function press(string $sessionId, string $key): BrowserResult
    {
        return $this->dispatch($sessionId, 'press', ['key' => $key]);
    }

    public function scroll(string $sessionId, int $pixels): BrowserResult
    {
        return $this->dispatch($sessionId, 'scroll', ['pixels' => $pixels]);
    }

    public function back(string $sessionId): BrowserResult
    {
        return $this->dispatch($sessionId, 'back');
    }

    public function close(string $sessionId): BrowserResult
    {
        return $this->dispatch($sessionId, 'close');
    }

    public function ping(): bool
    {
        try {
            $response = $this->http->timeout(min($this->timeout, 10))
                ->post($this->serviceUrl . '/action', [
                    'sessionId' => 'healthcheck',
                    'action'    => 'ping',
                    'args'      => [],
                ]);

            $data = $response->json();
            return (bool) ($data['ok'] ?? false) && (bool) ($data['pong'] ?? false);
        } catch (\Throwable $e) {
            $this->logger->warning('BrowserService::ping failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send an action to the service and normalise the response.
     */
    protected function dispatch(string $sessionId, string $action, array $args = []): BrowserResult
    {
        try {
            $response = $this->http->timeout($this->timeout)
                ->post($this->serviceUrl . '/action', [
                    'sessionId' => $sessionId,
                    'action'    => $action,
                    'args'      => $args,
                ]);

            $data = $response->json();

            if (!is_array($data)) {
                return BrowserResult::fail('Browser service returned an invalid response.');
            }

            if (!($data['ok'] ?? false)) {
                return BrowserResult::fail($data['error'] ?? 'unknown browser error');
            }

            return $this->mapResult($action, $data);
        } catch (\Throwable $e) {
            $this->logger->error('BrowserService::dispatch error', [
                'action'  => $action,
                'session' => $sessionId,
                'error'   => $e->getMessage(),
            ]);
            return BrowserResult::fail('Browser service unavailable: ' . $e->getMessage());
        }
    }

    /**
     * Map a successful service payload into a BrowserResult.
     *
     * A payload counts as a snapshot when it carries page structure (we key
     * on the presence of the 'links' array, which every snapshot includes).
     * Otherwise it's a plain confirmation (type-without-submit, close, ping).
     */
    protected function mapResult(string $action, array $data): BrowserResult
    {
        $confirmation = $this->confirmationLine($data);

        if (array_key_exists('links', $data) && isset($data['title'])) {
            return BrowserResult::withSnapshot(
                BrowserSnapshot::fromPayload($data),
                $confirmation,
            );
        }

        return BrowserResult::confirmed($confirmation ?? 'Done.');
    }

    /**
     * Derive a short confirmation line from action-specific payload keys.
     */
    protected function confirmationLine(array $data): ?string
    {
        return match (true) {
            isset($data['clickBlocked']) => "Could not click \"{$data['clickBlocked']}\": "
                                            . ($data['reason'] ?? 'element is covered by something on top.'),
            isset($data['clicked'])   => "Clicked: {$data['clicked']}",
            isset($data['typed'])     => "Typed \"{$data['typed']}\" into {$data['into']}"
                                         . (!empty($data['submitted']) ? ' (submitted)' : ''),
            isset($data['pressed'])   => "Pressed: {$data['pressed']}",
            isset($data['scrolled'])  => "Scrolled {$data['scrolled']}px",
            isset($data['navigated']) => "Went {$data['navigated']}",
            isset($data['closed'])    => 'Browser session closed.',
            isset($data['pong'])      => 'Browser service is online.',
            default                   => null,
        };
    }
}

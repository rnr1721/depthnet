<?php

namespace App\Services\Agent\Browser;

use App\Contracts\Agent\Browser\BrowserServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * Builds a BrowserService configured from a preset's execution context.
 *
 * The plugin is a stateless singleton — it can't hold a preset-specific
 * service URL or timeout. This factory reads those from the runtime context
 * and hands back a ready transport, keeping the plugin free of HTTP details
 * and free of per-preset state.
 */
class BrowserServiceFactory
{
    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
    ) {
    }

    public function fromContext(PluginExecutionContext $context): BrowserServiceInterface
    {
        $url = $context->get(
            'service_url',
            env('BROWSER_SERVICE_URL', 'http://browser-service:3001')
        );

        $timeout = (int) $context->get('request_timeout', 60);

        return new BrowserService($this->http, $this->logger, $url, $timeout);
    }
}

<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Models\AiPreset;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

/**
 * PlaywrightBrowserPlugin
 *
 * Gives agents a persistent, stateful browser backed by a dedicated
 * Playwright service running in Docker. Each preset gets its own browser
 * session that survives across thinking cycles.
 *
 * Requires the browser-service container to be running (profile: browser).
 */
class PlaywrightBrowserPlugin implements CommandPluginInterface
{
    use PluginExecutionMetaTrait;
    use PluginMethodTrait;
    use PluginConfigTrait;

    public function __construct(
        protected LoggerInterface $logger
    ) {
        $this->initializeConfig();
    }

    // ── CommandPluginInterface identity ──────────────────────────────────────

    public function getName(): string
    {
        return 'browser';
    }

    public function getDescription(): string
    {
        return 'Persistent web browser with session memory. Open pages, click, type, read structured snapshots. Session survives between thinking cycles.';
    }

    public function getInstructions(): array
    {
        return [
            'Open page:          [browser open]https://example.com[/browser]',
            'Search on Google:   [browser search]best php frameworks 2026[/browser]',
            'Page snapshot:      [browser snapshot][/browser]',
            'Click element:      [browser click]text=Submit[/browser]',
            'Type in field:      [browser type]{"selector":"input[name=q]","text":"hello"}[/browser]',
            'Press key:          [browser press]Enter[/browser]',
            'Scroll down:        [browser scroll]500[/browser]',
            'Go back:            [browser back][/browser]',
            'Close session:      [browser close][/browser]',
        ];
    }

    /**
     * Tool schema for tool_calls mode.
     *
     * Playwright-based persistent browser with session memory.
     * Sessions survive across thinking cycles — open a page, come back later.
     *
     * @return array OpenAI-compatible function descriptor
     */
    public function getToolSchema(): array
    {
        return [
            'name'        => 'browser',
            'description' => 'Persistent Playwright browser with session memory. '
                . 'Sessions survive across thinking cycles — open a page, reason about it, return later. '
                . 'Each preset gets its own session. '
                . 'Use for interactive sites, SPAs, and pages requiring JavaScript.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Browser operation to perform',
                        'enum'        => [
                            'open',       // open a URL
                            'search',     // search the web
                            'snapshot',   // get structured page snapshot
                            'click',      // click element
                            'type',       // type into input
                            'press',      // press keyboard key
                            'scroll',     // scroll page
                            'back',       // go back
                            'close',      // close session
                        ],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'open: full URL, e.g. "https://example.com".',
                            'search: search query string, e.g. "best PHP frameworks 2026".',
                            'snapshot: leave empty — returns structured page with links, inputs, buttons.',
                            'click: element selector or text, e.g. "text=Submit" or "#login-btn".',
                            'type: JSON {"selector":"input[name=q]","text":"hello"} .',
                            'press: key name, e.g. "Enter" or "Tab".',
                            'scroll: pixels to scroll, e.g. "500".',
                            'back/close: leave empty.',
                        ]),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function getSelfClosingTags(): array
    {
        return ['snapshot', 'back', 'close'];
    }

    // ── Config ───────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Browser Plugin',
                'description' => 'Requires browser-service container (Docker profile: browser)',
                'required'    => false,
            ],
            'service_url' => [
                'type'        => 'text',
                'label'       => 'Browser Service URL',
                'description' => 'URL of the Playwright browser-service',
                'value'       => 'http://browser-service:3001',
                'required'    => false,
            ],
            'request_timeout' => [
                'type'        => 'number',
                'label'       => 'Request Timeout (seconds)',
                'description' => 'HTTP timeout for browser actions',
                'min'         => 10,
                'max'         => 120,
                'value'       => 60,
                'required'    => false,
            ],
            'allowed_domains' => [
                'type'        => 'textarea',
                'label'       => 'Allowed Domains',
                'description' => 'Comma-separated whitelist. Empty = all allowed.',
                'placeholder' => 'example.com, google.com',
                'required'    => false,
            ],
            'blocked_domains' => [
                'type'        => 'textarea',
                'label'       => 'Blocked Domains',
                'description' => 'Comma-separated blacklist.',
                'placeholder' => 'malicious-site.com',
                'required'    => false,
            ],
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'         => false,
            'service_url'     => env('BROWSER_SERVICE_URL', 'http://browser-service:3001'),
            'request_timeout' => 60,
            'allowed_domains' => '',
            'blocked_domains' => '',
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (!empty($config['service_url']) && !filter_var($config['service_url'], FILTER_VALIDATE_URL)) {
            $errors['service_url'] = 'Invalid URL format.';
        }

        return $errors;
    }

    public function testConnection(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $response = Http::timeout(5)->get($this->serviceUrl() . '/health');
            return $response->ok() && ($response->json('ok') === true);
        } catch (\Throwable $e) {
            $this->logger->error('PlaywrightBrowserPlugin::testConnection failed: ' . $e->getMessage());
            return false;
        }
    }

    public function pluginReady(AiPreset $preset): void
    {
        // Nothing to prepare — the browser-service manages its own state
    }

    // ── Command dispatch ─────────────────────────────────────────────────────

    /**
     * Main entry point called by CommandExecutor.
     *
     * The $content arrives as "<subcommand> <payload>" or just "<subcommand>".
     * For the default tag [browser]url[/browser] the subcommand is treated as "open".
     */
    public function execute(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Browser plugin is disabled.';
        }

        $content = trim($content);

        if (empty($content)) {
            return $this->helpText();
        }

        // If it looks like a plain URL → treat as "open"
        if (filter_var($content, FILTER_VALIDATE_URL)) {
            return $this->dispatchAction($preset, 'open', ['url' => $content]);
        }

        return 'Error: Use correct syntax to navigate. ' . $this->helpText();
    }

    // ── Sub-command handlers (called via PluginMethodTrait magic) ────────────

    /**
     * [browser open]https://example.com[/browser]
     */
    public function open(string $content, AiPreset $preset): string
    {
        $url = trim($content);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return 'Error: Invalid URL — ' . $url;
        }

        if (!$this->isDomainAllowed($url)) {
            return 'Error: Domain not allowed by security policy.';
        }

        return $this->dispatchAction($preset, 'open', ['url' => $url]);
    }

    /**
     * [browser search]query[/browser]
     * Convenience: opens Google and searches.
     */
    public function search(string $content, AiPreset $preset): string
    {
        $query = trim($content);

        if (empty($query)) {
            return 'Error: search query cannot be empty.';
        }

        $url = 'https://www.google.com/search?q=' . urlencode($query);

        return $this->dispatchAction($preset, 'open', ['url' => $url]);
    }

    /**
     * [browser snapshot][/browser]
     * Returns structured view of the current page.
     */
    public function snapshot(string $content, AiPreset $preset): string
    {
        return $this->dispatchAction($preset, 'snapshot');
    }

    /**
     * [browser click]text=Submit[/browser]
     * or [browser click]#my-button[/browser]
     */
    public function click(string $content, AiPreset $preset): string
    {
        $selector = trim($content);

        if (empty($selector)) {
            return 'Error: selector cannot be empty.';
        }

        return $this->dispatchAction($preset, 'click', ['selector' => $selector]);
    }

    /**
     * [browser type]{"selector":"input[name=q]","text":"hello"}[/browser]
     */
    public function type(string $content, AiPreset $preset): string
    {
        $data = json_decode(trim($content), true);

        if (!$data || !isset($data['selector'], $data['text'])) {
            return 'Error: expected JSON {"selector":"...","text":"..."}';
        }

        return $this->dispatchAction($preset, 'type', [
            'selector' => $data['selector'],
            'text'     => $data['text'],
        ]);
    }

    /**
     * [browser press]Enter[/browser]
     */
    public function press(string $content, AiPreset $preset): string
    {
        $key = trim($content);

        if (empty($key)) {
            return 'Error: key name cannot be empty (e.g. Enter, Tab, Escape).';
        }

        return $this->dispatchAction($preset, 'press', ['key' => $key]);
    }

    /**
     * [browser scroll]500[/browser]
     */
    public function scroll(string $content, AiPreset $preset): string
    {
        $pixels = (int) trim($content) ?: 500;

        return $this->dispatchAction($preset, 'scroll', ['pixels' => $pixels]);
    }

    /**
     * [browser back][/browser]
     */
    public function back(string $content, AiPreset $preset): string
    {
        return $this->dispatchAction($preset, 'back');
    }

    /**
     * [browser close][/browser]
     */
    public function close(string $content, AiPreset $preset): string
    {
        return $this->dispatchAction($preset, 'close');
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    /**
     * Send an action request to the browser-service and format the response.
     *
     * @param  AiPreset  $preset
     * @param  string    $action
     * @param  array     $args
     * @return string
     */
    private function dispatchAction(AiPreset $preset, string $action, array $args = []): string
    {
        try {
            $response = Http::timeout($this->config['request_timeout'] ?? 60)
                ->post($this->serviceUrl() . '/action', [
                    'sessionId' => $this->sessionId($preset),
                    'action'    => $action,
                    'args'      => $args,
                ]);

            $data = $response->json();

            if (!($data['ok'] ?? false)) {
                return 'Browser error: ' . ($data['error'] ?? 'unknown error');
            }

            return $this->formatResult($action, $data);
        } catch (\Throwable $e) {
            $this->logger->error('PlaywrightBrowserPlugin::dispatchAction error: ' . $e->getMessage());
            return 'Browser service unavailable: ' . $e->getMessage();
        }
    }

    /**
     * Format the raw service response into a readable string for the agent.
     *
     * @param  string  $action
     * @param  array   $data
     * @return string
     */
    private function formatResult(string $action, array $data): string
    {
        // Actions that return a page snapshot (open / snapshot / search)
        if (in_array($action, ['open', 'snapshot']) || isset($data['title'])) {
            return $this->formatSnapshot($data);
        }

        // Simple confirmation actions
        return match (true) {
            isset($data['clicked'])   => "Clicked: {$data['clicked']}",
            isset($data['typed'])     => "Typed \"{$data['typed']}\" into {$data['into']}",
            isset($data['pressed'])   => "Pressed: {$data['pressed']}",
            isset($data['scrolled'])  => "Scrolled {$data['scrolled']}px",
            isset($data['navigated']) => "Navigated {$data['navigated']}. Now at: {$data['url']}",
            isset($data['closed'])    => 'Browser session closed.',
            isset($data['pong'])      => 'Browser service is online.',
            default                   => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        };
    }

    /**
     * Convert a snapshot payload into a compact, agent-friendly text block.
     *
     * @param  array  $data
     * @return string
     */
    private function formatSnapshot(array $data): string
    {
        $lines = [];

        $lines[] = '📄 ' . ($data['title'] ?? '[no title]');
        $lines[] = '🔗 ' . ($data['url']   ?? '[unknown url]');

        if (!empty($data['text'])) {
            $lines[] = '';
            $lines[] = '── Content ──';
            $lines[] = $data['text'];
        }

        if (!empty($data['inputs'])) {
            $lines[] = '';
            $lines[] = '── Inputs ──';
            foreach ($data['inputs'] as $input) {
                $hint = $input['placeholder'] ? " ({$input['placeholder']})" : '';
                $lines[] = "  [{$input['type']}] {$input['name']}{$hint}  selector: {$input['selector']}";
            }
        }

        if (!empty($data['buttons'])) {
            $lines[] = '';
            $lines[] = '── Buttons ──';
            foreach ($data['buttons'] as $btn) {
                $lines[] = "  [{$btn['text']}]  selector: {$btn['selector']}";
            }
        }

        if (!empty($data['links'])) {
            $lines[] = '';
            $lines[] = '── Links ──';
            foreach ($data['links'] as $link) {
                $lines[] = "  {$link['text']}  →  {$link['url']}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Stable session ID scoped to the preset.
     * Uses preset ID so the session persists across thinking cycles.
     *
     * @param  AiPreset  $preset
     * @return string
     */
    private function sessionId(AiPreset $preset): string
    {
        return 'preset_' . $preset->id;
    }

    /**
     * Base URL of the browser-service, with no trailing slash.
     *
     * @return string
     */
    private function serviceUrl(): string
    {
        return rtrim($this->config['service_url'] ?? env('BROWSER_SERVICE_URL', 'http://browser-service:3001'), '/');
    }

    /**
     * Resolve a domain list from config (comma-separated string → array).
     *
     * @param  string  $key
     * @return array
     */
    private function getDomainList(string $key): array
    {
        $raw = $this->config[$key] ?? '';
        if (empty($raw)) {
            return [];
        }
        return array_map('trim', explode(',', $raw));
    }

    /**
     * Check whether a URL's domain is permitted by plugin config.
     *
     * @param  string  $url
     * @return bool
     */
    private function isDomainAllowed(string $url): bool
    {
        $domain = parse_url($url, PHP_URL_HOST);

        if (in_array($domain, $this->getDomainList('blocked_domains'))) {
            return false;
        }

        $allowed = $this->getDomainList('allowed_domains');
        if (!empty($allowed)) {
            return in_array($domain, $allowed);
        }

        return true;
    }

    /**
     * Short help string shown when the agent uses the tag incorrectly.
     *
     * @return string
     */
    private function helpText(): string
    {
        return "Browser commands:\n"
            . "  browser open https://...\n"
            . "  browser search query\n"
            . "  browser snapshot\n"
            . "  browser click selector or text\n"
            . '  browser type {"selector":"...","text":"..."}' . "\n"
            . "  browser press Enter\n"
            . "  browser scroll 500\n"
            . "  browser back\n"
            . "  browser close";
    }
}

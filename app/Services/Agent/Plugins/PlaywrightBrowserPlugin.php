<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\Browser\BrowserServiceInterface;
use App\Contracts\Agent\CommandPluginInterface;
use App\Services\Agent\Browser\BrowserServiceFactory;
use App\Services\Agent\Browser\DTO\BrowserResult;
use App\Services\Agent\Browser\DTO\BrowserSnapshot;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * PlaywrightBrowserPlugin
 *
 * Thin command-layer over the browser-service. Responsibilities kept here:
 *   - plugin identity, config fields, instructions, tool schema
 *   - domain allow/block policy (a per-preset config concern)
 *   - rendering a BrowserSnapshot into agent-facing text
 *
 * All browser mechanics live in BrowserService. The plugin stays stateless;
 * a per-preset service is built on demand from the execution context.
 */
class PlaywrightBrowserPlugin implements CommandPluginInterface
{
    use PluginExecutionMetaTrait;
    use PluginMethodTrait;
    use PluginConfigTrait;

    private const SEARCH_ENGINES = [
        'google'     => ['label' => 'Google',     'url' => 'https://www.google.com/search?q='],
        'bing'       => ['label' => 'Bing',       'url' => 'https://www.bing.com/search?q='],
        'duckduckgo' => ['label' => 'DuckDuckGo', 'url' => 'https://duckduckgo.com/?q='],
        'brave'      => ['label' => 'Brave',      'url' => 'https://search.brave.com/search?q='],
    ];

    public function __construct(
        protected LoggerInterface $logger,
        protected BrowserServiceFactory $serviceFactory,
    ) {
    }

    // ── CommandPluginInterface identity ──────────────────────────────────────

    public function getName(): string
    {
        return 'browser';
    }

    public function getDescription(array $config = []): string
    {
        return 'Persistent web browser with session memory. Open pages, click, type, read structured snapshots. Session survives between thinking cycles.';
    }

    public function getInstructions(array $config = []): array
    {
        $searchEnabled = (bool) ($config['enable_search'] ?? false);
        $engine = self::SEARCH_ENGINES[$config['search_engine'] ?? 'google']['label'] ?? 'Google';

        $instructions = [
            'The browser returns a numbered snapshot. Each input, button and link has a number in [brackets].',
            'Act on elements BY THEIR NUMBER — this is the reliable way. Example: [browser click]3[/browser] clicks element [3].',
            'You may also use a CSS selector or text= if you must, but numbers are preferred and survive page changes.',
            '',
            'Open page:          [browser open]https://example.com[/browser]',
        ];

        if ($searchEnabled) {
            $instructions[] = 'Search ' . $engine . ':   [browser search]best php frameworks 2026[/browser]';
        }

        return array_merge($instructions, [
            'Page snapshot:      [browser snapshot][/browser]',
            'Click element:      [browser click]3[/browser]   (number from the snapshot)',
            'Type into field:    [browser type]2 | your text here[/browser]   (number | text)',
            'Type and submit:    [browser type]2 | your text | submit[/browser]   (adds Enter)',
            'Press key:          [browser press]Enter[/browser]',
            'Scroll down:        [browser scroll]500[/browser]',
            'Go back:            [browser back][/browser]',
            'Close session:      [browser close][/browser]',
            '',
            'Tip: to log in, type into the email field, then the password field, then add | submit to the last one (or press Enter).',
        ]);
    }

    public function getToolSchema(array $config = []): array
    {
        $searchEnabled = (bool) ($config['enable_search'] ?? false);
        $engine = self::SEARCH_ENGINES[$config['search_engine'] ?? 'google']['label'] ?? 'Google';

        $methods = ['open', 'snapshot', 'click', 'type', 'press', 'scroll', 'back', 'close'];
        if ($searchEnabled) {
            // place 'search' right after 'open'
            array_splice($methods, 1, 0, 'search');
        }

        $contentParts = [
            'Argument depends on method.',
            'open: full URL, e.g. "https://example.com".',
        ];
        if ($searchEnabled) {
            $contentParts[] = 'search: query string via ' . $engine . '.';
        }
        $contentParts = array_merge($contentParts, [
            'snapshot: leave empty — returns the numbered page structure.',
            'click: element NUMBER from the snapshot (preferred), e.g. "3". CSS selector or "text=..." also accepted.',
            'type: "NUMBER | text" or "NUMBER | text | submit" to press Enter after. NUMBER is the input number from the snapshot.',
            'press: key name, e.g. "Enter" or "Tab".',
            'scroll: pixels to scroll, e.g. "500".',
            'back/close: leave empty.',
        ]);

        return [
            'name'        => 'browser',
            'description' => 'Persistent Playwright browser with session memory. '
                . 'Sessions survive across thinking cycles. Each preset gets its own session. '
                . 'The page is returned as a numbered snapshot — act on elements by their number.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Browser operation to perform',
                        'enum'        => $methods,
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', $contentParts),
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

    /**
     * Each snapshot already contains the full current page state, so when the
     * agent fires several browser commands in one cycle, only the last result
     * body needs to be shown. Headers still appear so the model sees each ran.
     */
    public function collapseOutput(): bool
    {
        return true;
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
            'enable_search' => [
                'type'        => 'checkbox',
                'label'       => 'Enable direct search method',
                'description' => 'Direct search-by-URL often trips captchas and confuses the agent. '
                    . 'When off, the agent opens the search engine as a normal page instead, which works more reliably.',
                'value'       => false,
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
            'search_engine' => [
                'type'    => 'select',
                'label'   => 'Search Engine',
                'options' => array_map(fn ($e) => $e['label'], self::SEARCH_ENGINES),
                'value'   => 'google',
                'required' => false,
            ],
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'         => false,
            'enable_search'   => false,
            'service_url'     => env('BROWSER_SERVICE_URL', 'http://browser-service:3001'),
            'request_timeout' => 60,
            'allowed_domains' => '',
            'blocked_domains' => '',
            'search_engine'   => 'google',
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

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        // Nothing to prepare — the browser-service manages its own state.
    }

    // ── Command dispatch ─────────────────────────────────────────────────────

    /**
     * Default tag [browser]url[/browser] → treat as "open".
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Browser plugin is disabled.';
        }

        $content = trim($content);

        if (empty($content)) {
            return $this->helpText($context);
        }

        if (filter_var($content, FILTER_VALIDATE_URL)) {
            return $this->open($content, $context);
        }

        return 'Error: Use correct syntax to navigate. ' . $this->helpText($context);
    }

    // ── Sub-command handlers ─────────────────────────────────────────────────

    public function open(string $content, PluginExecutionContext $context): string
    {
        $url = trim($content);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return 'Error: Invalid URL — ' . $url;
        }

        if (!$this->isDomainAllowed($context, $url)) {
            return 'Error: Domain not allowed by security policy.';
        }

        return $this->render($this->service($context)->open($this->sessionId($context), $url));
    }

    public function search(string $content, PluginExecutionContext $context): string
    {
        if (!$context->get('enable_search', false)) {
            return 'Error: direct search is disabled. Open the search engine as a normal page instead, e.g. '
                . '[browser open]https://www.google.com[/browser], then type your query into the search box. '
                . 'This avoids captchas.';
        }

        $query = trim($content);
        if (empty($query)) {
            return 'Error: search query cannot be empty.';
        }

        $base = self::SEARCH_ENGINES[$context->get('search_engine', 'google')]['url']
            ?? self::SEARCH_ENGINES['google']['url'];
        $url = $base . urlencode($query);

        return $this->render($this->service($context)->open($this->sessionId($context), $url));
    }

    public function snapshot(string $content, PluginExecutionContext $context): string
    {
        return $this->render($this->service($context)->snapshot($this->sessionId($context)));
    }

    public function click(string $content, PluginExecutionContext $context): string
    {
        $target = trim($content);
        if (empty($target)) {
            return 'Error: nothing to click. Provide the element number from the snapshot, e.g. 3.';
        }

        return $this->render($this->service($context)->click($this->sessionId($context), $target));
    }

    /**
     * Accepts the model-friendly pipe syntax:
     *   "2 | hello world"            → type into element 2
     *   "2 | hello world | submit"   → type, then press Enter
     * Also tolerates the legacy JSON form {"selector":"...","text":"..."}.
     */
    public function type(string $content, PluginExecutionContext $context): string
    {
        $content = trim($content);

        [$target, $text, $submit] = $this->parseTypeArgs($content);

        if ($target === null || $text === null) {
            return 'Error: expected "NUMBER | text" (optionally "| submit"), e.g. 2 | john@mail.com | submit';
        }

        return $this->render(
            $this->service($context)->type($this->sessionId($context), $target, $text, $submit)
        );
    }

    public function press(string $content, PluginExecutionContext $context): string
    {
        $key = trim($content);
        if (empty($key)) {
            return 'Error: key name cannot be empty (e.g. Enter, Tab, Escape).';
        }

        return $this->render($this->service($context)->press($this->sessionId($context), $key));
    }

    public function scroll(string $content, PluginExecutionContext $context): string
    {
        $content = trim($content);
        $pixels = $content === '' ? 500 : (int) $content;

        return $this->render($this->service($context)->scroll($this->sessionId($context), $pixels));
    }

    public function back(string $content, PluginExecutionContext $context): string
    {
        return $this->render($this->service($context)->back($this->sessionId($context)));
    }

    public function close(string $content, PluginExecutionContext $context): string
    {
        return $this->render($this->service($context)->close($this->sessionId($context)));
    }

    // ── Type argument parsing ─────────────────────────────────────────────────

    /**
     * Parse the type command argument into [target, text, submit].
     * Returns [null, null, false] when unparseable.
     *
     * @return array{0: ?string, 1: ?string, 2: bool}
     */
    private function parseTypeArgs(string $content): array
    {
        // Legacy JSON form: {"selector":"...","text":"...","submit":true}
        if (str_starts_with($content, '{')) {
            $data = json_decode($content, true);
            if (is_array($data) && isset($data['selector'], $data['text'])) {
                return [(string) $data['selector'], (string) $data['text'], (bool) ($data['submit'] ?? false)];
            }
            return [null, null, false];
        }

        // Pipe form: "NUMBER | text" or "NUMBER | text | submit"
        $parts = array_map('trim', explode('|', $content));
        if (count($parts) < 2) {
            return [null, null, false];
        }

        $target = $parts[0];
        $submit = false;

        // A trailing "submit" flag
        if (count($parts) >= 3 && strtolower(end($parts)) === 'submit') {
            $submit = true;
            array_pop($parts);
        }

        // Everything between the target and the (optional) submit flag is the text.
        // Rejoin with "|" so the user's text may itself contain pipes.
        $text = implode(' | ', array_slice($parts, 1));

        return [$target, $text, $submit];
    }

    // ── Rendering BrowserResult → agent text ──────────────────────────────────

    private function render(BrowserResult $result): string
    {
        if (!$result->ok) {
            return 'Browser error: ' . ($result->error ?? 'unknown error');
        }

        if ($result->hasSnapshot()) {
            $snapshot = $this->formatSnapshot($result->snapshot);
            if (!$result->confirmation) {
                return $snapshot;
            }
            // A blocked click is reported as a non-fatal notice, not a success.
            $prefix = str_starts_with($result->confirmation, 'Could not') ? '⚠️ ' : '✓ ';
            return $prefix . $result->confirmation . "\n\n" . $snapshot;
        }

        return $result->confirmation ?? 'Done.';
    }

    private function formatSnapshot(BrowserSnapshot $s): string
    {
        $lines = [];

        $lines[] = '📄 ' . $s->title;
        $lines[] = '🔗 ' . $s->url;

        if ($s->modalOpen) {
            $lines[] = '⚠️  A dialog/modal is open. The elements below are inside it — act on them, or close the dialog to return to the page.';
        }

        if ($s->text !== '') {
            $lines[] = '';
            $lines[] = '── Content ──';
            $lines[] = $s->text;
            if ($s->textTruncated) {
                $lines[] = '… (text truncated)';
            }
        }

        if (!empty($s->inputs)) {
            $lines[] = '';
            $lines[] = '── Inputs ──';
            foreach ($s->inputs as $input) {
                $hint  = $input->placeholder ? " ({$input->placeholder})" : '';
                $value = $input->value !== null ? "  = \"{$input->value}\"" : '';
                $name  = $input->label !== '' ? ' ' . $input->label : '';
                $lines[] = "  [{$input->ref}] {$input->type}{$name}{$hint}{$value}";
            }
        }

        if (!empty($s->buttons)) {
            $lines[] = '';
            $lines[] = '── Buttons ──';
            foreach ($s->buttons as $btn) {
                $lines[] = "  [{$btn->ref}] {$btn->label}";
            }
        }

        if (!empty($s->links)) {
            $lines[] = '';
            $lines[] = '── Links ──';
            foreach ($s->links as $link) {
                $lines[] = "  [{$link->ref}] {$link->label}  →  {$link->url}";
            }
        }

        if ($s->isScrollable()) {
            $more = $s->hasMoreBelow() ? ', more below ↓' : ', end of page';
            $lines[] = '';
            $lines[] = "── Scroll: {$s->scrollPercent()}%{$more} ──";
        }

        return implode("\n", $lines);
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    private function service(PluginExecutionContext $context): BrowserServiceInterface
    {
        return $this->serviceFactory->fromContext($context);
    }

    private function sessionId(PluginExecutionContext $context): string
    {
        return 'preset_' . $context->preset->getId();
    }

    private function getDomainList(PluginExecutionContext $context, string $key): array
    {
        $raw = $context->get($key, '');
        if (empty($raw)) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    private function isDomainAllowed(PluginExecutionContext $context, string $url): bool
    {
        $domain = parse_url($url, PHP_URL_HOST);

        if (in_array($domain, $this->getDomainList($context, 'blocked_domains'), true)) {
            return false;
        }

        $allowed = $this->getDomainList($context, 'allowed_domains');
        if (!empty($allowed)) {
            return in_array($domain, $allowed, true);
        }

        return true;
    }

    private function helpText(PluginExecutionContext $context): string
    {
        $lines = [
            'Browser commands (act on elements by their snapshot number):',
            '  browser open https://...',
        ];

        if ($context->get('enable_search', false)) {
            $lines[] = '  browser search query';
        }

        return implode("\n", array_merge($lines, [
            '  browser snapshot',
            '  browser click 3            (number from snapshot)',
            '  browser type 2 | text      (number | text)',
            '  browser type 2 | text | submit',
            '  browser press Enter',
            '  browser scroll 500',
            '  browser back',
            '  browser close',
        ]));
    }
}

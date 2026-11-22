<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\Capabilities\VisionServiceInterface;
use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Mcp\McpClientInterface;
use App\Contracts\Agent\Mcp\McpServerRepositoryInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Exceptions\PluginUserException;
use App\Models\McpServer;
use App\Services\Agent\Capabilities\Vision\DTO\ImageData;
use App\Services\Agent\Mcp\Exceptions\McpToolErrorException;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Support\CommandHint;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * McpPlugin — stateless.
 *
 * Allows agents to call tools on connected MCP servers.
 *
 * Model-facing design rules:
 *   - The model always sees tool SIGNATURES, not just names:
 *       search_repositories(query: string, page?: integer) — Search for repos
 *     Without them the model guesses argument names.
 *   - Every error says what to do next (available servers, closest tool names,
 *     the expected signature). Errors are thrown as PluginUserException so the
 *     executor shows them under an ERROR header, not SUCCESS.
 *   - In tool_calls mode arguments travel as a real JSON object (`tool` +
 *     `arguments` properties) — no JSON escaped inside a string.
 */
class McpPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    /**
     * Method names with dedicated handlers. Anything else is a server_key,
     * so these words can never be used as server keys (enforced in connect()).
     */
    private const KNOWN_METHODS = ['list', 'tools', 'schema', 'connect', 'disconnect'];

    /** Max length of a tool description in compact listings. */
    private const SHORT_DESC_LENGTH = 120;

    /** Max enum values shown inline in a signature. */
    private const SIGNATURE_ENUM_LIMIT = 6;

    /** Max tool names per server in the [[mcp_servers]] placeholder ("names" detail). */
    private const PLACEHOLDER_NAMES_LIMIT = 40;

    public function __construct(
        protected McpClientInterface $mcpClient,
        protected McpServerRepositoryInterface $serverRepository,
        protected VisionServiceInterface $visionService,
        protected InputPoolServiceInterface $inputPool,
        protected LoggerInterface $logger,
        protected PlaceholderServiceInterface $placeholderService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
    ) {
    }

    public function getName(): string
    {
        return 'mcp';
    }

    public function getDescription(array $config = []): string
    {
        return 'Call tools on connected MCP (Model Context Protocol) servers.';
    }

    public function getInstructions(array $config = []): array
    {
        $instructions = [
            'List connected servers and their tools: [mcp list][/mcp]',
            'Refresh the tool list of a server: [mcp tools]server_key[/mcp]',
            'Show the full argument schema of one tool: [mcp schema]server_key tool_name[/mcp]',
            'Call a tool without arguments: [mcp server_key]tool_name[/mcp]',
            'Call a tool with JSON arguments: [mcp server_key]tool_name: {"key": "value"}[/mcp]',
            'Tools are listed as signatures: tool(arg: type, opt?: type). Arguments without "?" are required. '
                . 'Use exactly these argument names. ⚠ marks tools that change or delete data.',
        ];

        if ($config['allow_agent_connect'] ?? false) {
            $instructions[] = 'Connect a new MCP server: [mcp connect]{"url":"https://...","name":"Label","server_key":"key"}[/mcp]';
            $instructions[] = 'Disconnect a server: [mcp disconnect]server_key[/mcp]';
        }

        return $instructions;
    }

    /**
     * Context-free schema (fallback when the builder has no context).
     */
    public function getToolSchema(array $config = []): array
    {
        return $this->buildToolSchema($config, null);
    }

    /**
     * Optional hook (see ToolSchemaBuilder): same schema, but with the preset's
     * real servers — as an enum on `method` and a compact list in the description.
     * In tool_calls mode [[command_instructions]] is suppressed, so this is the
     * only place the model learns which servers exist without calling `list`.
     */
    public function getToolSchemaForContext(PluginExecutionContext $context): array
    {
        try {
            $servers = $this->serverRepository->allForPreset($context->preset);
        } catch (\Throwable $e) {
            $this->logger->warning('McpPlugin: could not load servers for tool schema', ['error' => $e->getMessage()]);
            $servers = null;
        }

        return $this->buildToolSchema($context->config, $servers);
    }

    /**
     * @param McpServer[]|null $servers null = unknown (no context)
     */
    private function buildToolSchema(array $config, ?array $servers): array
    {
        $allowConnect = $config['allow_agent_connect'] ?? false;

        $methods = ['list', 'tools', 'schema'];
        if ($allowConnect) {
            $methods[] = 'connect';
            $methods[] = 'disconnect';
        }

        $description = 'Call tools on connected MCP (Model Context Protocol) servers. '
            . 'To call a tool: method = server_key, tool = tool name, arguments = JSON object. '
            . 'Use list to see servers and tool signatures, schema for one tool\'s full argument schema. '
            . 'Signatures read tool(arg: type, opt?: type): arguments without "?" are required.';

        if ($allowConnect) {
            $description .= ' You can connect and disconnect servers if needed.';
        }

        $methodProperty = [
            'type'        => 'string',
            'description' => 'list | tools | schema'
                . ($allowConnect ? ' | connect | disconnect' : '')
                . ' | a server_key to call a tool on that server.',
        ];

        if ($servers !== null) {
            if (empty($servers)) {
                $description .= ' No servers are connected right now.';
            } else {
                $summary = array_map(function (McpServer $s) {
                    $count = count($s->getCachedTools());
                    return $s->getKey() . ($count ? " ({$count} tools)" : '');
                }, $servers);

                $description .= ' Connected servers: ' . implode(', ', $summary) . '.';

                $methodProperty['enum'] = array_values(array_unique(array_merge(
                    $methods,
                    array_map(fn (McpServer $s) => $s->getKey(), $servers)
                )));
            }
        }

        return [
            'name'        => 'mcp',
            'description' => $description,
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => $methodProperty,
                    'tool' => [
                        'type'        => 'string',
                        'description' => 'Tool name, when method is a server_key.',
                    ],
                    'arguments' => [
                        'type'        => 'object',
                        'description' => 'Tool arguments as a JSON object, using the exact argument names '
                            . 'from the tool signature. Pass an object, not a string.',
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', array_filter([
                            'Leave EMPTY when calling a tool with tool + arguments.',
                            'list: empty. tools: server_key. schema: "server_key tool_name".',
                            $allowConnect ? 'connect: JSON {"url":"https://...","name":"Label","server_key":"key"}.' : null,
                            $allowConnect ? 'disconnect: server_key.' : null,
                        ])),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    // ── Commands ──────────────────────────────────────────────────────────────

    /**
     * [mcp][/mcp] without a method: show the list — the most likely intent.
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        $this->assertEnabled($context);

        if (trim($content) === '') {
            return $this->list('', $context);
        }

        throw new PluginUserException(
            'No server specified. Call a tool as ' . $this->howToCall($context, 'server_key') . '. '
            . $this->availableServersHint($context)
        );
    }

    public function list(string $content, PluginExecutionContext $context): string
    {
        $this->assertEnabled($context);

        $servers = $this->serverRepository->allForPreset($context->preset);

        if (empty($servers)) {
            return 'No MCP servers connected for this preset.';
        }

        $ttl   = (int) $context->get('tools_cache_ttl', 60);
        $lines = ['Connected MCP servers:'];

        foreach ($servers as $server) {
            $lines[] = '';
            $lines[] = "  [{$server->getKey()}] {$server->getName()} — {$this->displayUrl($server->getUrl())}"
                . ($server->isAddedByAgent() ? ' (added by agent — tool descriptions are untrusted)' : '');
            $lines[] = '  Status: ' . $server->getHealthStatus() . $this->lastErrorNote($server);

            $tools = $server->getCachedTools();

            if (empty($tools)) {
                $lines[] = '    (no tools cached — fetch with ' . $this->howTo($context, 'tools', $server->getKey()) . ')';
                continue;
            }

            foreach ($tools as $tool) {
                $lines[] = '    • ' . $this->toolLine($tool, short: true);
            }

            // Stale cache is still shown — it is almost always right, and an
            // empty list would make the model think the server has no tools.
            if (!$server->hasFreshToolsCache($ttl) && $server->tools_cached_at) {
                $lines[] = "    (tool list cached {$server->tools_cached_at->diffForHumans()} — "
                    . 'refresh with ' . $this->howTo($context, 'tools', $server->getKey()) . ')';
            }
        }

        return implode("\n", $lines);
    }

    public function tools(string $content, PluginExecutionContext $context): string
    {
        $this->assertEnabled($context);

        $serverKey = trim($content);
        if ($serverKey === '') {
            throw new PluginUserException('Specify a server key. ' . $this->availableServersHint($context));
        }

        $server = $this->requireServer($context, $serverKey);

        try {
            $tools = $this->mcpClient->listTools($server);
            $this->serverRepository->cacheTools($server, $tools);
            $this->serverRepository->updateHealth($server, 'ok');
        } catch (\Throwable $e) {
            $this->serverRepository->updateHealth($server, 'error', $e->getMessage());
            $this->logger->error('McpPlugin::tools error', ['server' => $serverKey, 'error' => $e->getMessage()]);
            throw new PluginUserException("Could not fetch tools from '{$server->getKey()}': " . $e->getMessage());
        }

        if (empty($tools)) {
            return "Server '{$server->getKey()}' has no tools available.";
        }

        $lines = ["Tools available on '{$server->getKey()}':"];
        foreach ($tools as $tool) {
            $lines[] = '  • ' . $this->toolLine($tool, short: false);
        }

        return implode("\n", $lines);
    }

    /**
     * Full argument schema of one tool: [mcp schema]server_key tool_name[/mcp].
     * For nested objects / arrays of objects the one-line signature is not enough.
     */
    public function schema(string $content, PluginExecutionContext $context): string
    {
        $this->assertEnabled($context);

        $content = trim($content);
        $parts   = preg_split('/\s+/', $content, 2);

        // Also accept "server.tool" and "server:tool".
        if (count($parts) < 2 && preg_match('/^([^.:]+)[.:](.+)$/', $content, $m)) {
            $parts = [$m[1], $m[2]];
        }

        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            throw new PluginUserException('Format: ' . $this->howTo($context, 'schema', 'server_key tool_name'));
        }

        [$serverKey, $toolName] = [trim($parts[0]), trim($parts[1])];

        $server = $this->requireServer($context, $serverKey);
        $tool   = $this->findCachedTool($server, $toolName);

        // Not in cache — the cache may be stale; refresh once before giving up.
        if ($tool === null) {
            try {
                $tools = $this->mcpClient->listTools($server);
                $this->serverRepository->cacheTools($server, $tools);
                $tool = $this->findInTools($tools, $toolName);
            } catch (\Throwable $e) {
                throw new PluginUserException("Could not fetch tools from '{$server->getKey()}': " . $e->getMessage());
            }
        }

        if ($tool === null) {
            throw new PluginUserException(
                "Tool '{$toolName}' not found on '{$server->getKey()}'."
                . $this->suggestTools($context, $server, $toolName)
            );
        }

        $lines = [$this->signature($tool)];

        $description = trim((string) ($tool['description'] ?? ''));
        if ($description !== '') {
            $lines[] = $description;
        }

        $lines[] = '';
        $lines[] = 'inputSchema:';
        $lines[] = json_encode(
            $tool['inputSchema'] ?? ['type' => 'object'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return implode("\n", $lines);
    }

    public function connect(string $content, PluginExecutionContext $context): string
    {
        $this->assertEnabled($context);

        if (!$context->get('allow_agent_connect', false)) {
            throw new PluginUserException(
                'Agent-initiated MCP connections are not allowed. Ask the administrator to connect servers.'
            );
        }

        $data = json_decode(trim($content), true);
        if (!is_array($data) || empty($data['url']) || !is_string($data['url'])) {
            throw new PluginUserException(
                'Invalid JSON. Expected: {"url":"https://...","name":"Label","server_key":"key"}'
            );
        }

        $url    = trim($data['url']);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new PluginUserException("URL must be an absolute http(s) URL, got '{$url}'.");
        }

        $whitelist = $this->normalizeWhitelist($context->get('connect_whitelist', []));

        if (!empty($whitelist) && !$this->isHostWhitelisted($host, $whitelist)) {
            throw new PluginUserException("Host '{$host}' is not in the allowed whitelist.");
        }

        // Local / private targets only when the admin listed them explicitly —
        // otherwise an agent could probe the internal network (SSRF).
        if ($this->isPrivateHost($host) && !$this->isHostWhitelisted($host, $whitelist)) {
            throw new PluginUserException(
                "Host '{$host}' is local or private. Ask the administrator to add it to the connection whitelist."
            );
        }

        $serverKey = strtolower(trim((string) ($data['server_key'] ?? $this->keyFromUrl($url))));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $serverKey)) {
            throw new PluginUserException(
                "Invalid server_key '{$serverKey}': use 1-40 characters a-z, 0-9, _ or -, starting with a letter or digit."
            );
        }
        if (in_array($serverKey, self::KNOWN_METHODS, true)) {
            throw new PluginUserException("'{$serverKey}' is a reserved word, choose another server_key.");
        }

        $name = (string) ($data['name'] ?? $serverKey);

        if ($this->findServer($context, $serverKey)) {
            return "Server '{$serverKey}' is already connected.";
        }

        try {
            $server = $this->serverRepository->create($context->preset, [
                'name'       => $name,
                'server_key' => $serverKey,
                'url'        => $url,
                'headers'    => is_array($data['headers'] ?? null) ? $data['headers'] : [],
            ], addedByAgent: true);

            if ($this->mcpClient->ping($server)) {
                $tools = $this->mcpClient->listTools($server);
                $this->serverRepository->cacheTools($server, $tools);
                $this->serverRepository->updateHealth($server, 'ok');
                $toolCount = count($tools);

                return "Connected to '{$name}' [{$serverKey}]. Found {$toolCount} tool(s). "
                    . 'See them with ' . $this->howTo($context, 'tools', $serverKey) . '.';
            }

            return "Connected to '{$name}' [{$serverKey}], but the server did not respond to ping. Check the URL.";

        } catch (\Throwable $e) {
            $this->logger->error('McpPlugin::connect error', ['error' => $e->getMessage()]);
            throw new PluginUserException('Error connecting MCP server: ' . $e->getMessage());
        }
    }

    public function disconnect(string $content, PluginExecutionContext $context): string
    {
        $this->assertEnabled($context);

        if (!$context->get('allow_agent_connect', false)) {
            throw new PluginUserException('Agent-initiated MCP disconnections are not allowed.');
        }

        $server = $this->requireServer($context, trim($content));
        $key    = $server->getKey();

        $this->serverRepository->delete($server);

        return "Disconnected from '{$key}'.";
    }

    public function hasMethod(string $method): bool
    {
        // Known methods have handlers; everything else is treated as a server_key.
        return $method !== '';
    }

    public function callMethod(string $method, string $content, PluginExecutionContext $context): string
    {
        if (in_array($method, self::KNOWN_METHODS, true)) {
            return $this->{$method}($content, $context);
        }

        return $this->callServerTool($method, $content, $context);
    }

    // ── Placeholder ───────────────────────────────────────────────────────────

    /**
     * Registers [[mcp_servers]] — connected servers and their tools, rendered
     * from the DB cache only (no network). Detail level is configurable because
     * a big server (GitHub has ~100 tools) would flood the system prompt.
     */
    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $this->placeholderService->registerDynamic(
            'mcp_servers',
            'Connected MCP servers and their tools',
            fn () => $this->renderServersPlaceholder($context),
            $this->shortcodeScopeResolver->preset($context->preset->getId()),
            false,
            $this->getName()
        );
    }

    private function renderServersPlaceholder(PluginExecutionContext $context): string
    {
        try {
            $servers = $this->serverRepository->allForPreset($context->preset);
        } catch (\Throwable $e) {
            $this->logger->warning('McpPlugin: failed to render [[mcp_servers]]', ['error' => $e->getMessage()]);
            return '(MCP server list unavailable)';
        }

        if (empty($servers)) {
            return '(no MCP servers connected)';
        }

        $detail = (string) $context->get('placeholder_detail', 'names');
        $lines  = [];

        foreach ($servers as $server) {
            $tools  = $server->getCachedTools();
            $count  = count($tools);
            $header = "{$server->getKey()} — {$server->getName()} ("
                . ($count ? "{$count} tools, " : 'tools not fetched, ')
                . $server->getHealthStatus() . ')';

            if ($detail === 'servers' || $count === 0) {
                $lines[] = $header;
                continue;
            }

            if ($detail === 'signatures') {
                $lines[] = $header . ':';
                foreach ($tools as $tool) {
                    $lines[] = '  ' . $this->signature($tool);
                }
                continue;
            }

            // 'names' (default)
            $names = array_map(fn ($t) => (string) ($t['name'] ?? '?'), $tools);
            $shown = array_slice($names, 0, self::PLACEHOLDER_NAMES_LIMIT);
            $more  = count($names) - count($shown);

            $lines[] = $header . ': ' . implode(', ', $shown) . ($more > 0 ? ", +{$more} more" : '');
        }

        return implode("\n", $lines);
    }

    // ── Config ────────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable MCP Plugin',
                'description' => 'Allow agent to call tools on connected MCP servers',
                'required'    => false,
            ],
            'allow_agent_connect' => [
                'type'        => 'checkbox',
                'label'       => 'Allow Agent to Connect Servers',
                'description' => 'Allow agent to connect/disconnect MCP servers on its own. Disabled by default for safety.',
                'value'       => false,
                'required'    => false,
            ],
            'connect_whitelist' => [
                'type'        => 'textarea',
                'label'       => 'Connection Whitelist (one domain per line)',
                'description' => 'If set, agent can only connect to these domains (and their subdomains). '
                    . 'Leave empty to allow any public host. Local/private hosts (localhost, 192.168.x.x...) '
                    . 'are refused unless listed here explicitly.',
                'value'       => '',
                'required'    => false,
            ],
            'tools_cache_ttl' => [
                'type'        => 'number',
                'label'       => 'Tools Cache TTL (minutes)',
                'description' => 'After this time the cached tool list is marked stale in listings',
                'min'         => 1,
                'max'         => 1440,
                'value'       => 60,
                'required'    => false,
            ],
            'placeholder_detail' => [
                'type'        => 'select',
                'label'       => '[[mcp_servers]] detail',
                'description' => 'How much the [[mcp_servers]] placeholder shows. Signatures cost the most tokens.',
                'options'     => [
                    'servers'    => 'Servers only (key, name, tool count, status)',
                    'names'      => 'Servers + tool names',
                    'signatures' => 'Servers + full tool signatures',
                ],
                'value'       => 'names',
                'required'    => false,
            ],
            'max_result_chars' => [
                'type'        => 'number',
                'label'       => 'Max tool result length (characters)',
                'description' => 'Longer results are truncated with a note. 0 = no limit.',
                'min'         => 0,
                'max'         => 500000,
                'value'       => 20000,
                'required'    => false,
            ],
            'pool_source_name' => [
                'type'        => 'text',
                'label'       => 'Pool Source Name',
                'description' => 'The name of the source to which recognized media should be sent.',
                'placeholder' => 'e.g., Vision Input',
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['placeholder_detail'])
            && !in_array($config['placeholder_detail'], ['servers', 'names', 'signatures'], true)) {
            $errors['placeholder_detail'] = 'Must be servers, names or signatures.';
        }

        if (isset($config['max_result_chars']) && (int) $config['max_result_chars'] < 0) {
            $errors['max_result_chars'] = 'Must be 0 or greater.';
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'             => false,
            'allow_agent_connect' => false,
            'connect_whitelist'   => '',
            'tools_cache_ttl'     => 60,
            'placeholder_detail'  => 'names',
            'max_result_chars'    => 20000,
            'pool_source_name'    => '',
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
        return ['list'];
    }

    /**
     * When working with MCPs, a lot of context may be required.
     */
    public function needsLongContext(): bool
    {
        return true;
    }

    // ── Tool call ─────────────────────────────────────────────────────────────

    private function callServerTool(string $serverKey, string $content, PluginExecutionContext $context): string
    {
        $this->assertEnabled($context);

        $server = $this->requireServer($context, $serverKey);

        if (!$server->isEnabled()) {
            throw new PluginUserException("MCP server '{$server->getKey()}' is disabled.");
        }

        [$toolName, $arguments] = $this->parseToolCall($content, $server, $context);

        try {
            $blocks = $this->mcpClient->callToolRaw($server, $toolName, $arguments);
        } catch (McpToolErrorException $e) {
            // The server answered — it is healthy; this call was wrong.
            $this->serverRepository->updateHealth($server, 'ok');
            throw new PluginUserException($e->getMessage() . $this->toolHint($context, $server, $toolName));
        } catch (\Throwable $e) {
            $this->serverRepository->updateHealth($server, 'error', $e->getMessage());
            $this->logger->error('McpPlugin: tool call failed', [
                'server' => $server->getKey(),
                'tool'   => $toolName,
                'error'  => $e->getMessage(),
            ]);
            throw new PluginUserException(
                "Error calling '{$toolName}' on '{$server->getKey()}': " . $e->getMessage()
            );
        }

        $this->serverRepository->updateHealth($server, 'ok');

        return $this->renderBlocks($blocks, $server, $toolName, $context);
    }

    /**
     * Accepted forms:
     *   tool_name
     *   tool_name: {"key": "value"}
     *   tool_name {"key": "value"}
     *   tool_name: ```json {...} ```
     *   {"tool": "tool_name", "arguments": {...}}   ← tool_calls mode, tool + arguments
     *                                                  (ToolCallParser JSON-encodes them
     *                                                  into content when content is empty)
     *
     * Broken JSON is an error the model can fix — never silently an empty call.
     *
     * @return array{0: string, 1: array}
     */
    private function parseToolCall(string $content, McpServer $server, PluginExecutionContext $context): array
    {
        $content = trim($content);

        if ($content === '') {
            throw new PluginUserException(
                'No tool name given. Call it as ' . $this->howToCall($context, $server->getKey()) . '. '
                . 'See tools with ' . $this->howTo($context, 'tools', $server->getKey()) . '.'
            );
        }

        if ($content[0] === '{') {
            $data = json_decode($content, true);

            if (is_array($data) && isset($data['tool']) && is_string($data['tool']) && trim($data['tool']) !== '') {
                $toolName  = trim($data['tool']);
                $arguments = $data['arguments'] ?? [];

                if (is_string($arguments)) {
                    $arguments = trim($arguments) === '' ? [] : $this->decodeArguments($arguments, $toolName);
                }

                if (!is_array($arguments) || (!empty($arguments) && array_is_list($arguments))) {
                    throw new PluginUserException("'arguments' for '{$toolName}' must be a JSON object.");
                }

                return [$toolName, $arguments];
            }

            throw new PluginUserException(
                'Tool name is missing. Call it as ' . $this->howToCall($context, $server->getKey()) . '.'
            );
        }

        if (!preg_match('/^([^\s:{]+)\s*:?\s*(.*)$/s', $content, $m)) {
            throw new PluginUserException(
                'Could not read the tool name. Call it as ' . $this->howToCall($context, $server->getKey()) . '.'
            );
        }

        $toolName = $m[1];
        $rest     = trim($m[2]);

        return [$toolName, $rest === '' ? [] : $this->decodeArguments($rest, $toolName)];
    }

    private function decodeArguments(string $raw, string $toolName): array
    {
        $raw = trim($raw);

        // Strip a markdown fence if the model wrapped the JSON in one.
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $raw, $m)) {
            $raw = $m[1];
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PluginUserException(
                "Arguments for '{$toolName}' are not valid JSON (" . json_last_error_msg() . '). '
                . 'Expected an object like {"key": "value"}.'
            );
        }

        if (!is_array($decoded) || (!empty($decoded) && array_is_list($decoded))) {
            throw new PluginUserException("Arguments for '{$toolName}' must be a JSON object, not a list or a scalar.");
        }

        return $decoded;
    }

    /**
     * Render MCP content blocks to a text result, resolving image blocks via vision.
     *
     * text     → kept; pretty-printed JSON is compacted (same data, fewer tokens)
     * image    → VisionService::describe() → description text
     *            ├─ send_to_pool ON + pool ON → pushed to InputPoolService,
     *            │                              inline result gets a short notice
     *            └─ else                       → description inlined in the result
     * resource → text if present, else JSON
     * other    → JSON fallback
     *
     * The whole result is capped at max_result_chars, with a note telling the
     * model how much was cut and what to do about it.
     */
    private function renderBlocks(
        array $blocks,
        McpServer $server,
        string $toolName,
        PluginExecutionContext $context,
    ): string {
        $parts      = [];
        $sendToPool = $this->visionService->shouldSendToPool($context->preset)
            && $this->inputPool->isEnabled($context->preset);

        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';

            switch ($type) {
                case 'text':
                    $parts[] = $this->compactJson((string) ($block['text'] ?? ''));
                    break;

                case 'image':
                    $base64 = $block['data'] ?? '';
                    $mime   = $block['mimeType'] ?? 'image/jpeg';

                    if ($base64 === '') {
                        $parts[] = '[image: empty]';
                        break;
                    }

                    $image = new ImageData(
                        base64:      $base64,
                        mimeType:    $mime,
                        sourceLabel: "mcp:{$server->getKey()}:{$toolName}",
                    );

                    $result = $this->visionService->describeResult($image, null, $context->preset);

                    if (!$result->success) {
                        // Reason is human-readable — agent sees what went wrong, not silence.
                        $parts[] = "[image received — vision failed: {$result->error}]";
                        break;
                    }

                    $description = $result->text;

                    if ($sendToPool) {
                        $sourceName = empty($context->get('pool_source_name'))
                            ? "mcp_{$server->getKey()}"
                            : $context->get('pool_source_name');
                        $this->inputPool->add($context->preset->getId(), $sourceName, $description);
                        $parts[] = "[📷 image perceived via {$toolName} — routed to input pool]";
                    } else {
                        $parts[] = "[image: {$mime}]\n{$description}";
                    }
                    break;

                case 'resource':
                    $parts[] = $block['resource']['text']
                        ?? json_encode($block['resource'] ?? [], JSON_UNESCAPED_UNICODE);
                    break;

                default:
                    $parts[] = json_encode($block, JSON_UNESCAPED_UNICODE);
                    break;
            }
        }

        $result = implode("\n", array_filter($parts, fn ($p) => $p !== ''));

        if ($result === '') {
            return '(empty tool result)';
        }

        return $this->truncateResult($result, (int) $context->get('max_result_chars', 20000));
    }

    /**
     * Re-encode pretty-printed JSON without whitespace. Only touches text that
     * IS JSON and IS pretty-printed; decodes to objects (not assoc arrays) so
     * {} stays {}; skips anything with 19+ digit numbers to avoid float rounding.
     */
    private function compactJson(string $text): string
    {
        $trimmed = trim($text);

        if ($trimmed === '' || !in_array($trimmed[0], ['{', '['], true) || !str_contains($trimmed, "\n")) {
            return $text;
        }

        if (preg_match('/\d{19,}/', $trimmed)) {
            return $text;
        }

        $decoded = json_decode($trimmed);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $text;
        }

        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return $encoded === false ? $text : $encoded;
    }

    private function truncateResult(string $result, int $limit): string
    {
        if ($limit <= 0) {
            return $result;
        }

        $total = mb_strlen($result, 'UTF-8');
        if ($total <= $limit) {
            return $result;
        }

        return mb_substr($result, 0, $limit, 'UTF-8')
            . "\n\n[… truncated: showing the first {$limit} of {$total} characters. "
            . 'Narrow the request (filters, pagination, fewer fields) to see the rest.]';
    }

    // ── Tool signatures ───────────────────────────────────────────────────────

    /**
     * One listing line: "⚠ name(arg: type, opt?: type) — description".
     */
    private function toolLine(array $tool, bool $short): string
    {
        $line = $this->signature($tool);
        $desc = trim((string) ($tool['description'] ?? ''));

        if ($desc === '') {
            return $line;
        }

        if ($short) {
            $desc = strtok($desc, "\n") ?: $desc;
            if (mb_strlen($desc, 'UTF-8') > self::SHORT_DESC_LENGTH) {
                $desc = rtrim(mb_substr($desc, 0, self::SHORT_DESC_LENGTH, 'UTF-8')) . '…';
            }
        }

        return "{$line} — {$desc}";
    }

    /**
     * Compact signature from the tool's JSON Schema:
     *   create_issue(owner: string, repo: string, title: string, labels?: string[])
     * Destructive tools (annotations.destructiveHint) get a ⚠ prefix.
     */
    private function signature(array $tool): string
    {
        $schema   = $tool['inputSchema'] ?? [];
        $props    = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        $args = [];
        foreach ($props as $name => $prop) {
            $optional = in_array($name, $required, true) ? '' : '?';
            $args[]   = "{$name}{$optional}: " . $this->typeLabel(is_array($prop) ? $prop : []);
        }

        $prefix = !empty($tool['annotations']['destructiveHint']) ? '⚠ ' : '';

        return $prefix . ($tool['name'] ?? '?') . '(' . implode(', ', $args) . ')';
    }

    private function typeLabel(array $prop): string
    {
        if (isset($prop['enum']) && is_array($prop['enum'])) {
            $values = array_map(
                fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE),
                array_slice($prop['enum'], 0, self::SIGNATURE_ENUM_LIMIT)
            );
            return implode('|', $values) . (count($prop['enum']) > self::SIGNATURE_ENUM_LIMIT ? '|…' : '');
        }

        $type = $prop['type'] ?? null;

        if (is_array($type)) {
            return implode('|', $type);
        }

        if ($type === 'array') {
            $itemType = $prop['items']['type'] ?? null;
            return is_string($itemType) ? "{$itemType}[]" : 'array';
        }

        if ($type === null && (isset($prop['anyOf']) || isset($prop['oneOf']))) {
            $variants = array_map(
                fn ($v) => is_array($v) ? $this->typeLabel($v) : 'any',
                $prop['anyOf'] ?? $prop['oneOf']
            );
            return implode('|', array_unique($variants));
        }

        return is_string($type) ? $type : 'any';
    }

    // ── Hints for self-correction ─────────────────────────────────────────────

    /**
     * After a failed call: the expected signature if the tool exists,
     * closest names if it doesn't.
     */
    private function toolHint(PluginExecutionContext $context, McpServer $server, string $toolName): string
    {
        $tools = $server->getCachedTools();
        if (empty($tools)) {
            return '';
        }

        $tool = $this->findInTools($tools, $toolName);

        if ($tool !== null) {
            return "\nExpected: " . $this->signature($tool)
                . "\nFull schema: " . $this->howTo($context, 'schema', "{$server->getKey()} {$tool['name']}");
        }

        return $this->suggestTools($context, $server, $toolName);
    }

    private function suggestTools(PluginExecutionContext $context, McpServer $server, string $toolName): string
    {
        $names = array_filter(array_map(fn ($t) => (string) ($t['name'] ?? ''), $server->getCachedTools()));
        $close = $this->closestNames($toolName, $names);

        return ($close ? "\nDid you mean: " . implode(', ', $close) . '?' : '')
            . "\nSee all tools: " . $this->howTo($context, 'tools', $server->getKey());
    }

    // ── Syntax of hints ───────────────────────────────────────────────────────
    //
    // Every hint inside a result or an error is written in the syntax the model
    // actually uses this cycle — see CommandHint.

    /**
     * A plugin method call: [mcp tools]github[/mcp]  |  mcp(method="tools", content="github")
     */
    private function howTo(PluginExecutionContext $context, string $method, string $content = ''): string
    {
        return CommandHint::method($context->preset, $this->getName(), $method, $content);
    }

    /**
     * A tool call on a server — MCP-specific (tool + arguments), so not in CommandHint:
     *   [mcp github]tool_name: {"arg": "value"}[/mcp]
     *   mcp(method="github", tool="tool_name", arguments={"arg": "value"})
     */
    private function howToCall(
        PluginExecutionContext $context,
        string $serverKey,
        string $toolName = 'tool_name',
        string $arguments = '{"arg": "value"}',
    ): string {
        if (CommandHint::isToolCalls($context->preset)) {
            return "mcp(method=\"{$serverKey}\", tool=\"{$toolName}\", arguments={$arguments})";
        }

        return "[mcp {$serverKey}]{$toolName}: {$arguments}[/mcp]";
    }

    /**
     * Up to 3 names by edit distance, plus substring matches
     * ("search" → "search_repositories", "search_code").
     *
     * @param  string[] $candidates
     * @return string[]
     */
    private function closestNames(string $needle, array $candidates): array
    {
        $needle = strtolower($needle);
        $scored = [];

        foreach ($candidates as $name) {
            $lower    = strtolower($name);
            $distance = levenshtein($needle, $lower);

            if (str_contains($lower, $needle) || str_contains($needle, $lower)) {
                $distance = min($distance, 1);
            }

            if ($distance <= max(3, (int) (strlen($needle) / 3))) {
                $scored[$name] = $distance;
            }
        }

        asort($scored);

        return array_slice(array_keys($scored), 0, 3);
    }

    // ── Server lookup ─────────────────────────────────────────────────────────

    /**
     * Exact match first, then case-insensitive: ToolCallParser lowercases
     * `method`, and DB collations differ (SQLite/Postgres are case-sensitive).
     */
    private function findServer(PluginExecutionContext $context, string $serverKey): ?McpServer
    {
        if ($serverKey === '') {
            return null;
        }

        $server = $this->serverRepository->findByKey($context->preset, $serverKey);
        if ($server) {
            return $server;
        }

        foreach ($this->serverRepository->allForPreset($context->preset, false) as $candidate) {
            if (strcasecmp($candidate->getKey(), $serverKey) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    private function requireServer(PluginExecutionContext $context, string $serverKey): McpServer
    {
        $server = $this->findServer($context, $serverKey);

        if (!$server) {
            throw new PluginUserException(
                ($serverKey === '' ? 'No server key given. ' : "MCP server '{$serverKey}' not found. ")
                . $this->availableServersHint($context)
            );
        }

        return $server;
    }

    private function availableServersHint(PluginExecutionContext $context): string
    {
        $keys = array_map(
            fn (McpServer $s) => $s->getKey(),
            $this->serverRepository->allForPreset($context->preset)
        );

        return empty($keys)
            ? 'No MCP servers are connected for this preset.'
            : 'Connected servers: ' . implode(', ', $keys) . '.';
    }

    private function findCachedTool(McpServer $server, string $toolName): ?array
    {
        return $this->findInTools($server->getCachedTools(), $toolName);
    }

    private function findInTools(array $tools, string $toolName): ?array
    {
        foreach ($tools as $tool) {
            if (($tool['name'] ?? null) === $toolName) {
                return $tool;
            }
        }

        return null;
    }

    // ── Misc helpers ──────────────────────────────────────────────────────────

    private function assertEnabled(PluginExecutionContext $context): void
    {
        if (!$context->enabled) {
            throw new PluginUserException('MCP plugin is disabled.');
        }
    }

    private function lastErrorNote(McpServer $server): string
    {
        $error = trim((string) ($server->last_error ?? ''));

        if ($server->getHealthStatus() !== 'error' || $error === '') {
            return '';
        }

        return ' — last error: ' . mb_substr(strtok($error, "\n") ?: $error, 0, 150, 'UTF-8');
    }

    /**
     * Scheme + host + path only: query strings often carry tokens (?api_key=...),
     * and listings go into the model's context and the message history.
     */
    private function displayUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '(invalid url)';
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '');
    }

    private function keyFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        return trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower($host)), '_');
    }

    /**
     * @return string[]
     */
    private function normalizeWhitelist(mixed $whitelist): array
    {
        // connect_whitelist may come in as a textarea (string with newlines) or as array
        if (is_string($whitelist)) {
            $whitelist = explode("\n", $whitelist);
        }

        if (!is_array($whitelist)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($entry) => strtolower(trim((string) $entry)),
            $whitelist
        )));
    }

    /**
     * Exact host or a real subdomain of it. The old str_ends_with() check let
     * "evilgithub.com" through for "github.com".
     *
     * @param string[] $whitelist
     */
    private function isHostWhitelisted(string $host, array $whitelist): bool
    {
        foreach ($whitelist as $allowed) {
            $allowed = ltrim($allowed, '.');
            if ($allowed === '') {
                continue;
            }
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Literal check only (localhost, private/reserved IPs, .local/.internal).
     * Does not resolve DNS, so a public name pointing at a private IP passes —
     * that needs resolution at request time, in the HTTP layer.
     */
    private function isPrivateHost(string $host): bool
    {
        $host = trim(strtolower($host), '[]');

        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }
}

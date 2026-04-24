<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Mcp\McpClientInterface;
use App\Contracts\Agent\Mcp\McpServerRepositoryInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * McpPlugin — stateless.
 *
 * Allows agents to call tools on connected MCP servers.
 */
class McpPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected McpClientInterface $mcpClient,
        protected McpServerRepositoryInterface $serverRepository,
        protected LoggerInterface $logger
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
            'Call MCP tool without arguments: [mcp server_key]tool_name[/mcp]',
            'Call MCP tool with JSON arguments: [mcp server_key]tool_name: {"key": "value"}[/mcp]',
            'List all connected servers and their tools: [mcp list][/mcp]',
            'List tools for specific server: [mcp tools]server_key[/mcp]',
        ];

        if ($config['allow_agent_connect'] ?? false) {
            $instructions[] = 'Connect a new MCP server: [mcp connect]{"url":"https://...","name":"Label","server_key":"key"}[/mcp]';
            $instructions[] = 'Disconnect a server: [mcp disconnect]server_key[/mcp]';
        }

        return $instructions;
    }

    public function getToolSchema(array $config = []): array
    {
        $allowConnect = $config['allow_agent_connect'] ?? false;

        $methods = ['list', 'tools'];
        if ($allowConnect) {
            $methods[] = 'connect';
            $methods[] = 'disconnect';
        }

        $description = 'Call tools on connected MCP (Model Context Protocol) servers. '
            . 'Use list to see all servers and their tools. '
            . 'Use tools to refresh tool list for a specific server. '
            . 'To call a server tool, use the server_key as method.';

        if ($allowConnect) {
            $description .= ' You can connect and disconnect servers if needed.';
        }

        return [
            'name'        => 'mcp',
            'description' => $description,
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation: list (all servers), tools (refresh server tools), '
                            . ($allowConnect ? 'connect, disconnect, ' : '')
                            . 'or a server_key to call a tool on that server.',
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Depends on method.',
                            'list: leave empty.',
                            'tools: server_key.',
                            $allowConnect ? 'connect: JSON with url, name (optional), server_key (optional).' : '',
                            $allowConnect ? 'Example connect: {"url":"https://...","name":"Label","server_key":"key"}.' : '',
                            $allowConnect ? 'disconnect: server_key.' : '',
                            'For server tool call (method=server_key):',
                            '"tool_name" or "tool_name: {\"key\": \"value\"}".',
                            'Example: "search_repositories: {\"query\": \"depthnet\"}".',
                        ]),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: MCP plugin is disabled.';
        }

        return 'Error: Use correct syntax. No server specified.';
    }

    public function list(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: MCP plugin is disabled.';
        }

        $servers = $this->serverRepository->allForPreset($context->preset);

        if (empty($servers)) {
            return 'No MCP servers connected for this preset.';
        }

        $lines = ['Connected MCP servers:'];

        foreach ($servers as $server) {
            $lines[] = "\n  [{$server->getKey()}] {$server->getName()} — {$server->getUrl()}";
            $lines[] = "  Status: {$server->getHealthStatus()}";

            $tools = $this->getToolsForServer($context, $server);
            if (!empty($tools)) {
                foreach ($tools as $tool) {
                    $desc = $tool['description'] ?? '';
                    $lines[] = "    • {$tool['name']}" . ($desc ? ": {$desc}" : '');
                }
            } else {
                $lines[] = '    (no tools cached — call mcp tools' . $server->getKey() . '[/mcp] to fetch)';
            }
        }

        return implode("\n", $lines);
    }

    public function tools(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: MCP plugin is disabled.';
        }

        $serverKey = trim($content);
        if (empty($serverKey)) {
            return 'Error: Specify server key.';
        }

        $server = $this->serverRepository->findByKey($context->preset, $serverKey);
        if (!$server) {
            return "Error: MCP server '{$serverKey}' not found for this preset.";
        }

        try {
            $tools = $this->mcpClient->listTools($server);
            $this->serverRepository->cacheTools($server, $tools);
            $this->serverRepository->updateHealth($server, 'ok');

            if (empty($tools)) {
                return "Server '{$serverKey}' has no tools available.";
            }

            $lines = ["Tools available on '{$serverKey}':"];
            foreach ($tools as $tool) {
                $desc = $tool['description'] ?? '';
                $lines[] = "  • {$tool['name']}" . ($desc ? ": {$desc}" : '');
            }

            return implode("\n", $lines);

        } catch (\Throwable $e) {
            $this->serverRepository->updateHealth($server, 'error', $e->getMessage());
            $this->logger->error("McpPlugin::tools error", ['server' => $serverKey, 'error' => $e->getMessage()]);
            return "Error fetching tools from '{$serverKey}': " . $e->getMessage();
        }
    }

    public function connect(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: MCP plugin is disabled.';
        }

        if (!$context->get('allow_agent_connect', false)) {
            return 'Error: Agent-initiated MCP connections are not allowed. Ask administrator to connect servers.';
        }

        $data = json_decode(trim($content), true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data['url'])) {
            return 'Error: Invalid JSON. Use correct command and credentials syntax: {"url":"https://...","name":"Label","server_key":"key"}';
        }

        $serverKey = $data['server_key'] ?? $this->keyFromUrl($data['url']);
        $name      = $data['name'] ?? $serverKey;

        $whitelist = $context->get('connect_whitelist', []);
        // connect_whitelist may come in as a textarea (string with newlines) or as array
        if (is_string($whitelist)) {
            $whitelist = array_filter(array_map('trim', explode("\n", $whitelist)));
        }
        if (!empty($whitelist) && !$this->isUrlAllowed($data['url'], $whitelist)) {
            return "Error: URL '{$data['url']}' is not in the allowed whitelist.";
        }

        $existing = $this->serverRepository->findByKey($context->preset, $serverKey);
        if ($existing) {
            return "Server '{$serverKey}' is already connected.";
        }

        try {
            $server = $this->serverRepository->create($context->preset, [
                'name'       => $name,
                'server_key' => $serverKey,
                'url'        => $data['url'],
                'headers'    => $data['headers'] ?? [],
            ], addedByAgent: true);

            if ($this->mcpClient->ping($server)) {
                $tools = $this->mcpClient->listTools($server);
                $this->serverRepository->cacheTools($server, $tools);
                $this->serverRepository->updateHealth($server, 'ok');
                $toolCount = count($tools);
                return "Connected to '{$name}' [{$serverKey}]. Found {$toolCount} tool(s). Use mcp tools with key {$serverKey} to see them.";
            }

            return "Connected to '{$name}' [{$serverKey}], but server did not respond to ping. Check the URL.";

        } catch (\Throwable $e) {
            $this->logger->error("McpPlugin::connect error", ['error' => $e->getMessage()]);
            return 'Error connecting MCP server: ' . $e->getMessage();
        }
    }

    public function disconnect(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: MCP plugin is disabled.';
        }

        if (!$context->get('allow_agent_connect', false)) {
            return 'Error: Agent-initiated MCP disconnections are not allowed.';
        }

        $serverKey = trim($content);
        $server = $this->serverRepository->findByKey($context->preset, $serverKey);

        if (!$server) {
            return "Error: Server '{$serverKey}' not found.";
        }

        $this->serverRepository->delete($server);
        return "Disconnected from '{$serverKey}'.";
    }

    public function hasMethod(string $method): bool
    {
        if (in_array($method, ['list', 'tools', 'connect', 'disconnect'], true)) {
            return true;
        }

        // Everything else is treated as a server_key
        return true;
    }

    public function callMethod(string $method, string $content, PluginExecutionContext $context): string
    {
        if (method_exists($this, $method) && in_array($method, ['list', 'tools', 'connect', 'disconnect'], true)) {
            return $this->{$method}($content, $context);
        }

        // Treat $method as server_key
        return $this->callServerTool($method, $content, $context);
    }

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
                'description' => 'If set, agent can only connect to these domains. Leave empty to allow any.',
                'value'       => '',
                'required'    => false,
            ],
            'tools_cache_ttl' => [
                'type'        => 'number',
                'label'       => 'Tools Cache TTL (minutes)',
                'description' => 'How long to cache the tools list from each server',
                'min'         => 1,
                'max'         => 1440,
                'value'       => 60,
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'             => false,
            'allow_agent_connect' => false,
            'connect_whitelist'   => '',
            'tools_cache_ttl'     => 60,
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

    // ── Private helpers ───────────────────────────────────────────────────────

    private function callServerTool(string $serverKey, string $content, PluginExecutionContext $context): string
    {
        $server = $this->serverRepository->findByKey($context->preset, $serverKey);
        if (!$server) {
            return "Error: MCP server '{$serverKey}' not found. Use mcp list command to see connected servers.";
        }

        if (!$server->isEnabled()) {
            return "Error: MCP server '{$serverKey}' is disabled.";
        }

        [$toolName, $arguments] = $this->parseToolCall(trim($content));

        try {
            $result = $this->mcpClient->callTool($server, $toolName, $arguments);
            $this->serverRepository->updateHealth($server, 'ok');
            return $result;
        } catch (\Throwable $e) {
            $this->serverRepository->updateHealth($server, 'error', $e->getMessage());
            $this->logger->error("McpPlugin: tool call failed", [
                'server' => $serverKey,
                'tool'   => $toolName,
                'error'  => $e->getMessage(),
            ]);
            return "Error calling '{$toolName}' on '{$serverKey}': " . $e->getMessage();
        }
    }

    private function parseToolCall(string $content): array
    {
        if (str_contains($content, ':')) {
            [$toolName, $jsonPart] = explode(':', $content, 2);
            $toolName = trim($toolName);
            $arguments = json_decode(trim($jsonPart), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($arguments)) {
                return [$toolName, $arguments];
            }
        }

        return [trim($content), []];
    }

    private function getToolsForServer(PluginExecutionContext $context, \App\Models\McpServer $server): array
    {
        $ttl = (int) $context->get('tools_cache_ttl', 60);

        if ($server->hasFreshToolsCache($ttl)) {
            return $server->getCachedTools();
        }

        return [];
    }

    private function keyFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? $url;
        return preg_replace('/[^a-z0-9_]/', '_', strtolower($host));
    }

    private function isUrlAllowed(string $url, array $whitelist): bool
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        foreach ($whitelist as $allowed) {
            $allowed = trim($allowed);
            if (empty($allowed)) {
                continue;
            }
            if (str_ends_with($host, $allowed) || $host === $allowed) {
                return true;
            }
        }

        return false;
    }
}

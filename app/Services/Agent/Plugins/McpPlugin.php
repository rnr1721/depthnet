<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Mcp\McpClientInterface;
use App\Contracts\Agent\Mcp\McpServerRepositoryInterface;
use App\Models\AiPreset;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * McpPlugin
 *
 * Allows agents to call tools on connected MCP servers.
 *
 * Command syntax:
 *   [mcp server_key]tool_name: {"arg": "value"}[/mcp]
 *   [mcp server_key]tool_name[/mcp]           — no args
 *   [mcp connect]https://url.com[/mcp]         — connect new server (if allowed)
 *   [mcp disconnect]server_key[/mcp]           — disconnect (if allowed)
 *   [mcp list][/mcp]                           — list available servers + tools
 *   [mcp tools]server_key[/mcp]                — list tools for specific server
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
        $this->initializeConfig();
    }

    public function getName(): string
    {
        return 'mcp';
    }

    public function getDescription(): string
    {
        return 'Call tools on connected MCP (Model Context Protocol) servers.';
    }

    public function getInstructions(): array
    {
        $instructions = [
            'Call MCP tool without arguments: [mcp server_key]tool_name[/mcp]',
            'Call MCP tool with JSON arguments: [mcp server_key]tool_name: {"key": "value"}[/mcp]',
            'List all connected servers and their tools: [mcp list][/mcp]',
            'List tools for specific server: [mcp tools]server_key[/mcp]',
        ];

        if ($this->config['allow_agent_connect'] ?? false) {
            $instructions[] = 'Connect a new MCP server: [mcp connect]{"url":"https://...","name":"Label","server_key":"key"}[/mcp]';
            $instructions[] = 'Disconnect a server: [mcp disconnect]server_key[/mcp]';
        }

        return $instructions;
    }

    /**
     * Default execute: [mcp server_key]tool_name: {args}[/mcp]
     */
    public function execute(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: MCP plugin is disabled.';
        }

        return 'Error: Use [mcp server_key]tool_name[/mcp] or [mcp list][/mcp]. No server specified.';
    }

    /**
     * List all connected servers and their tools
     * [mcp list][/mcp]
     */
    public function list(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: MCP plugin is disabled.';
        }

        $servers = $this->serverRepository->allForPreset($preset);

        if (empty($servers)) {
            return 'No MCP servers connected for this preset.';
        }

        $lines = ['Connected MCP servers:'];

        foreach ($servers as $server) {
            $lines[] = "\n  [{$server->getKey()}] {$server->getName()} — {$server->getUrl()}";
            $lines[] = "  Status: {$server->getHealthStatus()}";

            $tools = $this->getToolsForServer($server);
            if (!empty($tools)) {
                foreach ($tools as $tool) {
                    $desc = $tool['description'] ?? '';
                    $lines[] = "    • {$tool['name']}" . ($desc ? ": {$desc}" : '');
                }
            } else {
                $lines[] = '    (no tools cached — call [mcp tools]' . $server->getKey() . '[/mcp] to fetch)';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Fetch and show tools for a specific server
     * [mcp tools]server_key[/mcp]
     */
    public function tools(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: MCP plugin is disabled.';
        }

        $serverKey = trim($content);
        if (empty($serverKey)) {
            return 'Error: Specify server key. Use [mcp tools]server_key[/mcp]';
        }

        $server = $this->serverRepository->findByKey($preset, $serverKey);
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

    /**
     * Connect a new MCP server (agent-initiated, requires allow_agent_connect)
     * [mcp connect]{"url":"...","name":"...","server_key":"..."}[/mcp]
     */
    public function connect(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: MCP plugin is disabled.';
        }

        if (!($this->config['allow_agent_connect'] ?? false)) {
            return 'Error: Agent-initiated MCP connections are not allowed. Ask administrator to connect servers.';
        }

        $data = json_decode(trim($content), true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data['url'])) {
            return 'Error: Invalid JSON. Use [mcp connect]{"url":"https://...","name":"Label","server_key":"key"}[/mcp]';
        }

        $serverKey = $data['server_key'] ?? $this->keyFromUrl($data['url']);
        $name      = $data['name'] ?? $serverKey;

        // Check whitelist
        $whitelist = $this->config['connect_whitelist'] ?? [];
        if (!empty($whitelist) && !$this->isUrlAllowed($data['url'], $whitelist)) {
            return "Error: URL '{$data['url']}' is not in the allowed whitelist.";
        }

        // Check if already connected
        $existing = $this->serverRepository->findByKey($preset, $serverKey);
        if ($existing) {
            return "Server '{$serverKey}' is already connected.";
        }

        try {
            $server = $this->serverRepository->create($preset, [
                'name'       => $name,
                'server_key' => $serverKey,
                'url'        => $data['url'],
                'headers'    => $data['headers'] ?? [],
            ], addedByAgent: true);

            // Immediately ping and fetch tools
            if ($this->mcpClient->ping($server)) {
                $tools = $this->mcpClient->listTools($server);
                $this->serverRepository->cacheTools($server, $tools);
                $this->serverRepository->updateHealth($server, 'ok');
                $toolCount = count($tools);
                return "Connected to '{$name}' [{$serverKey}]. Found {$toolCount} tool(s). Use [mcp tools]{$serverKey}[/mcp] to see them.";
            }

            return "Connected to '{$name}' [{$serverKey}], but server did not respond to ping. Check the URL.";

        } catch (\Throwable $e) {
            $this->logger->error("McpPlugin::connect error", ['error' => $e->getMessage()]);
            return 'Error connecting MCP server: ' . $e->getMessage();
        }
    }

    /**
     * Disconnect a server (agent-initiated, requires allow_agent_connect)
     * [mcp disconnect]server_key[/mcp]
     */
    public function disconnect(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: MCP plugin is disabled.';
        }

        if (!($this->config['allow_agent_connect'] ?? false)) {
            return 'Error: Agent-initiated MCP disconnections are not allowed.';
        }

        $serverKey = trim($content);
        $server = $this->serverRepository->findByKey($preset, $serverKey);

        if (!$server) {
            return "Error: Server '{$serverKey}' not found.";
        }

        $this->serverRepository->delete($server);
        return "Disconnected from '{$serverKey}'.";
    }

    /**
     * PluginMethodTrait routes [mcp server_key]...[/mcp] here via callMethod().
     *
     * But server_key is the "method" in command syntax, so we need to intercept
     * unknown methods and treat them as server calls.
     */
    public function hasMethod(string $method): bool
    {
        // Own named methods
        if (in_array($method, ['list', 'tools', 'connect', 'disconnect'])) {
            return true;
        }

        // Everything else is treated as a server_key — handled at callMethod level
        return true;
    }

    public function callMethod(string $method, string $content, AiPreset $preset): string
    {
        // Own named methods
        if (method_exists($this, $method) && in_array($method, ['list', 'tools', 'connect', 'disconnect'])) {
            return $this->{$method}($content, $preset);
        }

        // Treat $method as server_key
        return $this->callServerTool($method, $content, $preset);
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
            'enabled'             => true,
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
    public function testConnection(): bool
    {
        return $this->isEnabled();
    }
    public function pluginReady(AiPreset $preset): void
    {
    }

    public function getSelfClosingTags(): array
    {
        return ['list'];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Route [mcp server_key]tool_name: {args}[/mcp] to the actual MCP call
     */
    private function callServerTool(string $serverKey, string $content, AiPreset $preset): string
    {
        $server = $this->serverRepository->findByKey($preset, $serverKey);
        if (!$server) {
            return "Error: MCP server '{$serverKey}' not found. Use [mcp list][/mcp] to see connected servers.";
        }

        if (!$server->isEnabled()) {
            return "Error: MCP server '{$serverKey}' is disabled.";
        }

        // Parse "tool_name: {json}" or just "tool_name"
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

    /**
     * Parse "tool_name: {json}" into [toolName, arguments]
     */
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

    private function getToolsForServer(\App\Models\McpServer $server): array
    {
        $ttl = $this->config['tools_cache_ttl'] ?? 60;

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

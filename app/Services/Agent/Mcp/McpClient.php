<?php

namespace App\Services\Agent\Mcp;

use App\Contracts\Agent\Mcp\McpClientInterface;
use App\Models\McpServer;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

/**
 * MCP HTTP/SSE client
 *
 * Implements JSON-RPC 2.0 over Streamable HTTP transport as per MCP specification.
 * Supports protocol handshake (initialize/initialized), tools/list and tools/call.
 *
 * @see https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http
 */
class McpClient implements McpClientInterface
{
    private const JSONRPC_VERSION = '2.0';
    private const TIMEOUT = 30;

    /**
     * Protocol versions in preference order (newest first).
     * Client will attempt negotiation starting from the most recent version.
     */
    private const SUPPORTED_PROTOCOL_VERSIONS = [
        '2025-03-26',
        '2024-11-05',
    ];

    /**
     * Cache of established sessions: server key => session info.
     * Avoids re-initializing on every call within the same request lifecycle.
     */
    private array $sessions = [];

    /**
     * Cache of resolved POST endpoints for legacy SSE transport.
     * Legacy SSE servers send the message endpoint URL in the first SSE event.
     * Format: server_key => absolute URL
     */
    private array $sseEndpoints = [];

    /**
     * Incremental request ID counter for JSON-RPC.
     */
    private int $requestId = 0;

    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch available tools from MCP server.
     *
     * @param McpServer $server
     * @return array [['name' => ..., 'description' => ..., 'inputSchema' => ...], ...]
     */
    public function listTools(McpServer $server): array
    {
        $this->ensureInitialized($server);

        $response = $this->rpcCall($server, 'tools/list', []);

        return $response['result']['tools'] ?? [];
    }

    /**
     * Call a tool on MCP server.
     *
     * @param McpServer $server
     * @param string $toolName
     * @param array $arguments
     * @return string Text result from the tool
     *
     * @throws \RuntimeException on MCP-level or transport errors
     */
    public function callTool(McpServer $server, string $toolName, array $arguments): string
    {
        $this->ensureInitialized($server);

        $response = $this->rpcCall($server, 'tools/call', [
            'name'      => $toolName,
            'arguments' => (object) $arguments, // MCP spec: arguments MUST be an object
        ]);

        if (isset($response['error'])) {
            throw new \RuntimeException(
                "MCP tool error [{$toolName}]: "
                . ($response['error']['message'] ?? 'Unknown error')
                . (isset($response['error']['code']) ? " (code: {$response['error']['code']})" : '')
            );
        }

        return $this->extractContent($response['result'] ?? []);
    }

    /**
     * Ping server to check availability.
     * Uses the MCP 'ping' method first, falls back to 'tools/list' for older servers.
     */
    public function ping(McpServer $server): bool
    {
        try {
            $this->ensureInitialized($server);

            // Try the lightweight 'ping' method first (MCP spec)
            try {
                $this->rpcCall($server, 'ping', [], timeout: 5);
                return true;
            } catch (\RuntimeException $e) {
                // Method not found (-32601) — server doesn't support ping, fall back
                if (str_contains($e->getMessage(), '-32601') || str_contains($e->getMessage(), 'Method not found')) {
                    $this->rpcCall($server, 'tools/list', [], timeout: 5);
                    return true;
                }
                throw $e;
            }
        } catch (\Throwable $e) {
            $this->logger->debug("MCP ping failed for [{$server->getKey()}]: {$e->getMessage()}");
            return false;
        }
    }

    // -------------------------------------------------------------------------
    //  Protocol Handshake
    // -------------------------------------------------------------------------

    /**
     * Ensure the MCP session is initialized for the given server.
     * Per MCP spec: client MUST send initialize, wait for response,
     * then send notifications/initialized before any other requests.
     */
    protected function ensureInitialized(McpServer $server): void
    {
        $key = $server->getKey();

        if (isset($this->sessions[$key])) {
            return;
        }

        // Legacy SSE servers (phone-mcp, older implementations) are stateless —
        // they don't implement the initialize handshake from the Streamable HTTP spec.
        // Each JSON-RPC call goes directly to the message endpoint discovered via SSE.
        if ($server->getTransport() === 'sse') {
            $this->sessions[$key] = [
                'protocolVersion'    => '2024-11-05',
                'serverCapabilities' => [],
                'serverInfo'         => ['name' => 'legacy-sse'],
                'sessionId'          => null,
            ];
            return;
        }

        $initParams = [
            'protocolVersion' => self::SUPPORTED_PROTOCOL_VERSIONS[0],
            'capabilities'    => (object) [],
            'clientInfo'      => [
                'name'    => config('app.name', 'Laravel MCP Client'),
                'version' => '1.0.0',
            ],
        ];

        $response = $this->rpcCall($server, 'initialize', $initParams, skipInit: true);

        $serverProtocolVersion = $response['result']['protocolVersion'] ?? null;

        if ($serverProtocolVersion && !in_array($serverProtocolVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            $this->logger->warning(
                "MCP server [{$key}] uses unsupported protocol version: {$serverProtocolVersion}. "
                . 'Proceeding with best-effort compatibility.'
            );
        }

        // Send notifications/initialized (JSON-RPC notification — no id, no response expected)
        $this->sendNotification($server, 'notifications/initialized');

        $this->sessions[$key] = [
            'protocolVersion'    => $serverProtocolVersion ?? self::SUPPORTED_PROTOCOL_VERSIONS[0],
            'serverCapabilities' => $response['result']['capabilities'] ?? [],
            'serverInfo'         => $response['result']['serverInfo'] ?? [],
            'sessionId'          => null, // will be set from response header if provided
        ];

        $this->logger->debug("MCP session initialized for [{$key}]", [
            'protocolVersion' => $this->sessions[$key]['protocolVersion'],
            'serverInfo'      => $this->sessions[$key]['serverInfo'],
        ]);
    }

    /**
     * Clear cached session, forcing re-initialization on next call.
     */
    public function resetSession(McpServer $server): void
    {
        unset($this->sessions[$server->getKey()]);
        unset($this->sseEndpoints[$server->getKey()]);
    }

    // -------------------------------------------------------------------------
    //  JSON-RPC Transport
    // -------------------------------------------------------------------------

    /**
     * Send a JSON-RPC request and return the parsed response.
     *
     * @param McpServer $server
     * @param string $method
     * @param array|object $params
     * @param int $timeout
     * @param bool $skipInit Internal flag to prevent recursion during initialize
     * @return array Decoded JSON-RPC response
     *
     * @throws \RuntimeException on transport or protocol errors
     */
    protected function rpcCall(
        McpServer $server,
        string $method,
        array|object $params,
        int $timeout = self::TIMEOUT,
        bool $skipInit = false,
    ): array {
        $id = $this->nextId();

        $payload = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id'      => $id,
            'method'  => $method,
            'params'  => empty((array) $params) ? (object) [] : $params,
        ];

        $headers = $this->buildHeaders($server);

        // Legacy SSE returns an already-parsed JSON-RPC array (response arrives
        // asynchronously on the stream). Streamable HTTP returns a Response to parse.
        if ($server->getTransport() === 'sse') {
            return $this->sendLegacySse($server, $payload, $headers, $timeout);
        }

        $response = $this->sendWithRetry($server, $payload, $headers, $timeout);

        return $this->parseResponse($response, $id, $server);
    }

    /**
     * Send a JSON-RPC notification (no id, no response expected).
     */
    protected function sendNotification(McpServer $server, string $method, array|object $params = []): void
    {
        // Legacy SSE is stateless per-call in our implementation — there's no
        // persistent session to notify. Skip notifications entirely for it.
        if ($server->getTransport() === 'sse') {
            return;
        }

        $payload = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'method'  => $method,
        ];

        if (!empty((array) $params)) {
            $payload['params'] = $params;
        }

        $headers = $this->buildHeaders($server);

        try {
            $this->http
                ->withHeaders($headers)
                ->timeout(10)
                ->post($server->getUrl(), $payload);
        } catch (\Throwable $e) {
            // Notifications are fire-and-forget; log but don't fail
            $this->logger->debug(
                "MCP notification [{$method}] to [{$server->getKey()}] failed: {$e->getMessage()}"
            );
        }
    }

    /**
     * Legacy SSE transport (MCP 2024-11-05, servers like phone-mcp).
     *
     * This is an asynchronous bidirectional protocol:
     *   1. GET /sse        — open a persistent stream; first event is `endpoint`
     *   2. POST {endpoint} — send the JSON-RPC payload; server replies 202 Accepted
     *   3. GET /sse stream — the actual JSON-RPC response arrives back on the open stream
     *
     * The GET stream and the POST must overlap in time, so we drive the stream
     * with curl_multi (non-blocking) and fire the POST as a normal request once
     * the endpoint is known. We keep reading the stream until a `message` event
     * carrying our request id appears, then tear everything down.
     *
     * Each rpcCall opens a fresh short-lived stream (new sessionId) — stateless
     * by design, since PHP can't hold a connection open between agent requests.
     *
     * @see https://modelcontextprotocol.io/specification/2024-11-05/basic/transports#server-sent-events-sse
     */
    protected function sendLegacySse(
        McpServer $server,
        array $payload,
        array $headers,
        int $timeout,
    ): array {
        $key = $server->getKey();

        $buffer       = '';
        $endpoint     = null;
        $posted       = false;
        $result       = null;
        $requestId    = $payload['id'] ?? null;
        $deadline     = microtime(true) + $timeout;

        $curlHeaders = ['Accept: text/event-stream'];
        foreach (array_merge($headers, $server->getHeaders()) as $name => $value) {
            // skip Content-Type on the GET stream
            if (strcasecmp($name, 'Content-Type') === 0) {
                continue;
            }
            $curlHeaders[] = "{$name}: {$value}";
        }

        // GET /sse — non-blocking stream driven by curl_multi
        $stream = curl_init($server->getUrl());
        curl_setopt_array($stream, [
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buffer, &$endpoint, &$result, $requestId, $server) {
                $buffer .= $chunk;

                // Discover endpoint once
                if ($endpoint === null) {
                    $found = $this->extractSseEndpoint($buffer, $server->getUrl());
                    if ($found !== null) {
                        $endpoint = $found;
                    }
                }

                // Look for the JSON-RPC response for our request id
                $found = $this->extractSseMessage($buffer, $requestId);
                if ($found !== null) {
                    $result = $found;
                    return 0; // abort — we have our answer
                }

                return strlen($chunk);
            },
        ]);

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $stream);

        $active = null;
        do {
            curl_multi_exec($mh, $active);

            // Once we know the endpoint and haven't posted yet — send the payload
            if ($endpoint !== null && !$posted) {
                $this->sseEndpoints[$key] = $endpoint;
                $this->logger->debug("MCP legacy SSE endpoint discovered for [{$key}]", [
                    'endpoint' => $endpoint,
                ]);

                $postResponse = $this->http
                    ->withHeaders(array_merge($headers, ['Content-Type' => 'application/json']))
                    ->timeout($timeout)
                    ->post($endpoint, $payload);

                // Server accepts with 202; the real answer comes via the stream
                if ($postResponse->status() >= 400) {
                    curl_multi_remove_handle($mh, $stream);
                    throw new \RuntimeException(
                        "MCP legacy SSE [{$key}] POST HTTP {$postResponse->status()}: {$postResponse->body()}"
                    );
                }

                $posted = true;
            }

            // Got the answer (WRITEFUNCTION returned 0) — stop
            if ($result !== null) {
                break;
            }

            // Timeout guard
            if (microtime(true) > $deadline) {
                curl_multi_remove_handle($mh, $stream);
                throw new \RuntimeException(
                    "MCP legacy SSE [{$key}] timed out after {$timeout}s waiting for response"
                );
            }

            if ($active) {
                curl_multi_select($mh, 0.5);
            }
        } while ($active || $result === null);

        $errno = curl_multi_errno($mh);
        $streamErrno = curl_errno($stream);
        curl_multi_remove_handle($mh, $stream);

        if ($result === null) {
            // errno 23 = we aborted via WRITEFUNCTION (expected); anything else is real
            if ($streamErrno !== 0 && $streamErrno !== 23) {
                throw new \RuntimeException(
                    "MCP legacy SSE [{$key}] stream error {$streamErrno}: " . curl_strerror($streamErrno)
                );
            }
            throw new \RuntimeException(
                "MCP legacy SSE [{$key}] stream closed without a matching response"
            );
        }

        return $result;
    }

    /**
     * Scan an SSE buffer for a `message` event carrying the JSON-RPC response
     * that matches the given request id. Returns the decoded array or null if
     * not yet present in the buffer.
     *
     * Legacy SSE servers deliver responses as:
     *   event: message
     *   data: {"jsonrpc":"2.0","id":1,"result":{...}}
     */
    protected function extractSseMessage(string $buffer, ?int $requestId): ?array
    {
        $buffer = str_replace(["\r\n", "\r"], "\n", $buffer);
        $events = preg_split('/\n{2,}/', $buffer);

        foreach ($events as $event) {
            $eventType = null;
            $dataLines = [];

            foreach (explode("\n", $event) as $line) {
                if ($line === '' || str_starts_with($line, ':')) {
                    continue;
                }

                $colonPos = strpos($line, ':');
                if ($colonPos === false) {
                    continue;
                }

                $field = substr($line, 0, $colonPos);
                $value = ltrim(substr($line, $colonPos + 1), ' ');

                match ($field) {
                    'event' => $eventType = $value,
                    'data'  => $dataLines[] = $value,
                    default => null,
                };
            }

            // Skip the endpoint event and anything without data
            if ($eventType === 'endpoint' || empty($dataLines)) {
                continue;
            }

            $json = json_decode(implode("\n", $dataLines), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }

            // Match by request id; accept id-less responses that carry result/error
            if (
                (isset($json['id']) && $json['id'] === $requestId)
                || (!isset($json['id']) && (isset($json['result']) || isset($json['error'])))
            ) {
                return $json;
            }
        }

        return null;
    }

    /**
     * Extract the POST endpoint URL from the SSE stream's `endpoint` event.
     *
     * Legacy SSE servers send an initial event like:
     *   event: endpoint
     *   data: /message?sessionId=abc123
     *
     * The data may be an absolute URL or a path relative to the SSE base URL.
     */
    protected function extractSseEndpoint(string $body, string $sseUrl): ?string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $events = preg_split('/\n{2,}/', $body);

        foreach ($events as $event) {
            $eventType = null;
            $dataLines = [];

            foreach (explode("\n", $event) as $line) {
                if ($line === '' || str_starts_with($line, ':')) {
                    continue;
                }

                $colonPos = strpos($line, ':');
                if ($colonPos === false) {
                    continue;
                }

                $field = substr($line, 0, $colonPos);
                $value = ltrim(substr($line, $colonPos + 1), ' ');

                match ($field) {
                    'event' => $eventType = $value,
                    'data'  => $dataLines[] = $value,
                    default => null,
                };
            }

            if ($eventType !== 'endpoint' || empty($dataLines)) {
                continue;
            }

            $endpointData = trim(implode('', $dataLines));

            // Absolute URL — use as-is
            if (str_starts_with($endpointData, 'http://') || str_starts_with($endpointData, 'https://')) {
                return $endpointData;
            }

            // Relative path — resolve against SSE base URL (scheme + host + port)
            $parsed = parse_url($sseUrl);
            $base = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '');
            if (isset($parsed['port'])) {
                $base .= ':' . $parsed['port'];
            }

            return $base . '/' . ltrim($endpointData, '/');
        }

        return null;
    }

    /**
     * Send HTTP request with retry logic for transient failures (Streamable HTTP transport).
     */
    protected function sendWithRetry(
        McpServer $server,
        array $payload,
        array $headers,
        int $timeout,
        int $maxRetries = 2,
    ): Response {
        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                // Exponential backoff: 500ms, 1500ms
                usleep(500_000 * $attempt);
                $this->logger->debug(
                    "MCP retry #{$attempt} for [{$server->getKey()}] method={$payload['method']}"
                );
            }

            try {
                $response = $this->http
                    ->withHeaders($headers)
                    ->timeout($timeout)
                    ->post($server->getUrl(), $payload);

                if ($response->successful()) {
                    return $response;
                }

                // Don't retry client errors (4xx) except 429
                $status = $response->status();
                if ($status >= 400 && $status < 500 && $status !== 429) {
                    throw new \RuntimeException(
                        "MCP server [{$server->getKey()}] HTTP {$status}: {$response->body()}"
                    );
                }

                $lastException = new \RuntimeException(
                    "MCP server [{$server->getKey()}] HTTP {$status}: {$response->body()}"
                );
            } catch (\RuntimeException $e) {
                throw $e; // re-throw non-retryable errors
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        throw new \RuntimeException(
            "MCP server [{$server->getKey()}] unavailable after " . ($maxRetries + 1) . " attempts: "
            . ($lastException?->getMessage() ?? 'Unknown error'),
            0,
            $lastException
        );
    }

    /**
     * Build HTTP headers for a request.
     */
    protected function buildHeaders(McpServer $server): array
    {
        $headers = [
            'Content-Type'         => 'application/json',
            'Accept'               => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => $this->getSessionProtocolVersion($server),
        ];

        // Include Mcp-Session-Id if we have one
        $sessionId = $this->sessions[$server->getKey()]['sessionId'] ?? null;
        if ($sessionId) {
            $headers['Mcp-Session-Id'] = $sessionId;
        }

        return array_merge($headers, $server->getHeaders());
    }

    /**
     * Get the negotiated protocol version for this server, or default to newest.
     */
    protected function getSessionProtocolVersion(McpServer $server): string
    {
        return $this->sessions[$server->getKey()]['protocolVersion']
            ?? self::SUPPORTED_PROTOCOL_VERSIONS[0];
    }

    // -------------------------------------------------------------------------
    //  Response Parsing
    // -------------------------------------------------------------------------

    /**
     * Parse HTTP response, handling both JSON and SSE content types.
     */
    protected function parseResponse(Response $response, int $requestId, McpServer $server): array
    {
        // Capture session ID from response headers if present
        $sessionId = $response->header('Mcp-Session-Id');
        if ($sessionId && isset($this->sessions[$server->getKey()])) {
            $this->sessions[$server->getKey()]['sessionId'] = $sessionId;
        }

        $contentType = $response->header('Content-Type') ?? '';

        // Legacy SSE servers always respond with SSE stream on the message endpoint,
        // but some (phone-mcp) don't set Content-Type: text/event-stream correctly.
        // Force SSE parsing for the whole transport rather than relying on the header.
        if ($server->getTransport() === 'sse' || str_contains($contentType, 'text/event-stream')) {
            return $this->parseSseBody($response->body(), $requestId);
        }

        $json = $response->json();

        if (!is_array($json)) {
            throw new \RuntimeException(
                "MCP server [{$server->getKey()}] returned invalid JSON response"
            );
        }

        return $json;
    }

    /**
     * Parse SSE response body per the SSE specification.
     *
     * Handles:
     * - Multi-line `data:` fields (concatenated with newlines)
     * - \r\n and \r line endings
     * - Event filtering: only processes events matching the request ID
     * - Skips comments (lines starting with ':')
     *
     * @see https://html.spec.whatwg.org/multipage/server-sent-events.html#event-stream-interpretation
     */
    protected function parseSseBody(string $body, int $requestId): array
    {
        // Normalize line endings to \n
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        // Split into events by double newline
        $events = preg_split('/\n{2,}/', $body);

        foreach ($events as $event) {
            $dataLines = [];
            $eventType = null;

            foreach (explode("\n", $event) as $line) {
                // Skip empty lines and comments
                if ($line === '' || str_starts_with($line, ':')) {
                    continue;
                }

                // Parse field: value
                $colonPos = strpos($line, ':');
                if ($colonPos === false) {
                    $field = $line;
                    $value = '';
                } else {
                    $field = substr($line, 0, $colonPos);
                    $value = substr($line, $colonPos + 1);
                    // Remove single leading space after colon if present (SSE spec)
                    if (str_starts_with($value, ' ')) {
                        $value = substr($value, 1);
                    }
                }

                match ($field) {
                    'data'  => $dataLines[] = $value,
                    'event' => $eventType = $value,
                    default => null, // ignore id, retry, unknown fields
                };
            }

            if (empty($dataLines)) {
                continue;
            }

            // Per SSE spec: multiple data lines are joined with newlines
            $dataStr = implode("\n", $dataLines);
            $json = json_decode($dataStr, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }

            // Match by request ID, or accept if it has a result/error (some servers omit id)
            if (
                (isset($json['id']) && $json['id'] === $requestId)
                || (!isset($json['id']) && (isset($json['result']) || isset($json['error'])))
            ) {
                return $json;
            }
        }

        throw new \RuntimeException(
            "No matching JSON-RPC response found in SSE stream for request ID {$requestId}"
        );
    }

    // -------------------------------------------------------------------------
    //  Content Extraction
    // -------------------------------------------------------------------------

    /**
     * Extract text content from MCP tool result.
     *
     * MCP spec: result.content is an array of content blocks.
     * Supported types: text, resource, image (base64 — returned as metadata),
     * and unknown types (serialized to JSON as fallback).
     */
    protected function extractContent(array $result): string
    {
        $content = $result['content'] ?? [];

        if (empty($content)) {
            // Fallback for non-standard responses
            return $result['text'] ?? json_encode($result, JSON_UNESCAPED_UNICODE);
        }

        $parts = [];

        foreach ($content as $block) {
            $type = $block['type'] ?? '';

            $parts[] = match ($type) {
                'text'     => $block['text'] ?? '',
                'resource' => $block['resource']['text']
                    ?? json_encode($block['resource'], JSON_UNESCAPED_UNICODE),
                'image'    => '[image: ' . ($block['mimeType'] ?? 'unknown') . ']',
                default    => json_encode($block, JSON_UNESCAPED_UNICODE),
            };
        }

        return implode("\n", array_filter($parts));
    }

    /**
     * Generate the next sequential request ID.
     */
    protected function nextId(): int
    {
        return ++$this->requestId;
    }
}

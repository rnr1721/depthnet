<?php

namespace App\Contracts\Agent\Mcp;

use App\Models\McpServer;

/**
 * Contract for communicating with MCP (Model Context Protocol) servers.
 *
 * Provides tool discovery and invocation over the MCP Streamable HTTP transport.
 * Implementations handle protocol handshake, JSON-RPC messaging, and SSE parsing.
 *
 * @see https://modelcontextprotocol.io/specification
 */
interface McpClientInterface
{
    /**
     * Fetch the list of tools available on the MCP server.
     *
     * Each tool is represented as an associative array containing at minimum:
     *  - `name`        (string) — unique tool identifier
     *  - `description` (string) — human-readable description
     *  - `inputSchema` (array)  — JSON Schema describing the expected arguments
     *
     * @param McpServer $server  The server to query.
     * @return array<int, array{name: string, description: string, inputSchema: array}>
     *
     * @throws \RuntimeException If the server is unreachable or returns an invalid response.
     */
    public function listTools(McpServer $server): array;

    /**
     * Invoke a tool on the MCP server and return its text result.
     *
     * Arguments are passed as a key-value map matching the tool's input schema.
     * The response content blocks are merged into a single string.
     *
     * @param McpServer $server     The server hosting the tool.
     * @param string    $toolName   Tool identifier as returned by {@see listTools()}.
     * @param array     $arguments  Tool arguments (will be sent as a JSON object).
     * @return string               Extracted text content from the tool's response.
     *
     * @throws \RuntimeException        On transport errors (timeouts, HTTP failures).
     * @throws \RuntimeException        On MCP-level errors (tool not found, execution failure).
     * @throws \InvalidArgumentException If the tool name is empty.
     */
    public function callTool(McpServer $server, string $toolName, array $arguments): string;

    /**
     * Check whether the MCP server is reachable and responding.
     *
     * Implementations should use a lightweight check (e.g. the MCP `ping` method)
     * and return within a short timeout. This method MUST NOT throw exceptions —
     * any failure is reported by returning `false`.
     *
     * @param McpServer $server  The server to check.
     * @return bool              `true` if the server responded successfully, `false` otherwise.
     */
    public function ping(McpServer $server): bool;
}

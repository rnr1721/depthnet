<?php

namespace App\Contracts\Agent\Mcp;

use App\Models\AiPreset;
use App\Models\McpServer;

/**
 * Repository contract for managing MCP server registrations.
 *
 * Handles CRUD operations, health tracking, and tool cache
 * for MCP servers associated with AI presets.
 */
interface McpServerRepositoryInterface
{
    /**
     * Get all MCP servers registered for a given preset.
     *
     * @param AiPreset $preset      The preset whose servers to retrieve.
     * @param bool     $enabledOnly When true, returns only servers with is_enabled = true.
     * @return McpServer[]          Servers ordered by name.
     */
    public function allForPreset(AiPreset $preset, bool $enabledOnly = true): array;

    /**
     * Find a specific MCP server by its unique key within a preset.
     *
     * @param AiPreset $preset    The owning preset.
     * @param string   $serverKey Unique server identifier (e.g. "github", "jira").
     * @return McpServer|null     The server, or null if not found.
     */
    public function findByKey(AiPreset $preset, string $serverKey): ?McpServer;

    /**
     * Register a new MCP server for a preset.
     *
     * Expected keys in $data:
     *  - `name`       (string, required) — human-readable display name
     *  - `server_key` (string, required) — unique identifier within the preset
     *  - `url`        (string, required) — MCP server endpoint URL
     *  - `transport`  (string, default "sse") — transport type
     *  - `headers`    (array,  default [])  — custom HTTP headers
     *  - `is_enabled` (bool,   default true)
     *
     * @param AiPreset $preset       The owning preset.
     * @param array    $data         Server configuration (see above).
     * @param bool     $addedByAgent Whether the server was added automatically by an AI agent.
     * @return McpServer             The newly created server record.
     *
     * @throws \Illuminate\Database\QueryException On duplicate server_key or constraint violation.
     */
    public function create(AiPreset $preset, array $data, bool $addedByAgent = false): McpServer;

    /**
     * Delete an MCP server registration and its cached data.
     *
     * @param McpServer $server The server to remove.
     * @return bool             True if the record was deleted, false if it didn't exist.
     */
    public function delete(McpServer $server): bool;

    /**
     * Update the health status of an MCP server after a connectivity check.
     *
     * @param McpServer   $server The server to update.
     * @param string      $status Health status (e.g. "healthy", "unreachable", "error").
     * @param string|null $error  Error message if status is not healthy, null otherwise.
     */
    public function updateHealth(McpServer $server, string $status, ?string $error = null): void;

    /**
     * Cache the list of tools discovered from an MCP server.
     *
     * Overwrites any previously cached tools. Sets tools_cached_at to the current timestamp.
     *
     * @param McpServer $server The server whose tools to cache.
     * @param array     $tools  Tool definitions as returned by McpClientInterface::listTools().
     */
    public function cacheTools(McpServer $server, array $tools): void;
}

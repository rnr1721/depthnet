<?php

namespace App\Services\Agent\Mcp;

use App\Contracts\Agent\Mcp\McpServerRepositoryInterface;
use App\Models\AiPreset;
use App\Models\McpServer;

/**
 * Eloquent-backed repository for MCP server registrations.
 *
 * All queries are scoped to a specific AiPreset to ensure
 * tenant isolation between different preset configurations.
 */
class McpServerRepository implements McpServerRepositoryInterface
{
    /** @inheritDoc */
    public function allForPreset(AiPreset $preset, bool $enabledOnly = true): array
    {
        $query = McpServer::forPreset($preset->getId());

        if ($enabledOnly) {
            $query->enabled();
        }

        return $query->orderBy('name')->get()->all();
    }

    /** @inheritDoc */
    public function findByKey(AiPreset $preset, string $serverKey): ?McpServer
    {
        return McpServer::forPreset($preset->getId())
            ->where('server_key', $serverKey)
            ->first();
    }

    /** @inheritDoc */
    public function create(AiPreset $preset, array $data, bool $addedByAgent = false): McpServer
    {
        return McpServer::create([
            'preset_id'      => $preset->getId(),
            'name'           => $data['name'],
            'server_key'     => $data['server_key'],
            'url'            => $data['url'],
            'transport'      => $data['transport'] ?? 'sse',
            'headers'        => $data['headers'] ?? [],
            'is_enabled'     => $data['is_enabled'] ?? true,
            'added_by_agent' => $addedByAgent,
        ]);
    }

    /** @inheritDoc */
    public function delete(McpServer $server): bool
    {
        return (bool) $server->delete();
    }

    /** @inheritDoc */
    public function updateHealth(McpServer $server, string $status, ?string $error = null): void
    {
        $server->update([
            'health_status'   => $status,
            'last_error'      => $error,
            'last_checked_at' => now(),
        ]);
    }

    /** @inheritDoc */
    public function cacheTools(McpServer $server, array $tools): void
    {
        $server->update([
            'tools_cache'    => $tools,
            'tools_cached_at' => now(),
        ]);
    }
}

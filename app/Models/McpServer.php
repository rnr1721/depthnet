<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpServer extends Model
{
    protected $table = 'mcp_servers';

    protected $fillable = [
        'preset_id',
        'name',
        'server_key',
        'url',
        'transport',
        'headers',
        'is_enabled',
        'added_by_agent',
        'health_status',
        'last_checked_at',
        'last_error',
        'tools_cache',
        'tools_cached_at',
    ];

    protected $casts = [
        'headers'        => 'array',
        'tools_cache'    => 'array',
        'is_enabled'     => 'boolean',
        'added_by_agent' => 'boolean',
        'last_checked_at' => 'datetime',
        'tools_cached_at' => 'datetime',
    ];

    protected $attributes = [
        'transport'     => 'sse',
        'is_enabled'    => true,
        'added_by_agent' => false,
        'health_status' => 'unknown',
        'headers'       => '{}',
    ];

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    public function getId(): int
    {
        return $this->id;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function getKey(): string
    {
        return $this->server_key;
    }
    public function getUrl(): string
    {
        return $this->url;
    }
    public function getHeaders(): array
    {
        return $this->headers ?? [];
    }
    public function isEnabled(): bool
    {
        return $this->is_enabled;
    }
    public function isAddedByAgent(): bool
    {
        return $this->added_by_agent;
    }
    public function getHealthStatus(): string
    {
        return $this->health_status;
    }

    public function getCachedTools(): array
    {
        return $this->tools_cache ?? [];
    }

    public function hasFreshToolsCache(int $ttlMinutes = 60): bool
    {
        if (!$this->tools_cached_at || empty($this->tools_cache)) {
            return false;
        }
        return $this->tools_cached_at->diffInMinutes(now()) < $ttlMinutes;
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeForPreset($query, int $presetId)
    {
        return $query->where('preset_id', $presetId);
    }
}

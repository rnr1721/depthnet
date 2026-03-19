<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'key_prefix',
        'key_hash',
        'last_used_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at'   => 'datetime',
        ];
    }

    // ──────────────────────────────────────────
    // Relations
    // ──────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ──────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }

    public function scopeRevoked($query)
    {
        return $query->whereNotNull('revoked_at');
    }

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Mark key as used right now.
     */
    public function touchUsed(): void
    {
        $this->timestamps = false;
        $this->update(['last_used_at' => now()]);
        $this->timestamps = true;
    }
}

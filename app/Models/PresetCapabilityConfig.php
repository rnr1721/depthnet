<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Capability provider configuration attached to a preset.
 *
 * @property int         $id
 * @property int         $preset_id
 * @property string      $capability   'embedding' | 'image' | 'audio' | ...
 * @property string      $driver       'novita' | 'openai' | ...
 * @property array|null  $config       Driver-specific settings
 * @property bool        $is_active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class PresetCapabilityConfig extends Model
{
    protected $fillable = [
        'preset_id',
        'capability',
        'driver',
        'config',
        'is_active',
    ];

    protected $casts = [
        'config'    => 'array',
        'is_active' => 'boolean',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForPreset($query, int $presetId)
    {
        return $query->where('preset_id', $presetId);
    }

    public function scopeForCapability($query, string $capability)
    {
        return $query->where('capability', $capability);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Get a single config value by key.
     */
    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}

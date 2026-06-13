<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property int         $id
 * @property int         $preset_id
 * @property int         $voice_preset_id
 * @property int         $sort_order
 * @property bool        $is_enabled
 * @property int         $context_limit
 * @property string|null $label
 *
 * @property-read AiPreset $preset
 * @property-read AiPreset $voicePreset
 */
class PresetInnerVoiceConfig extends Model
{
    protected $fillable = [
        'preset_id',
        'voice_preset_id',
        'sort_order',
        'is_enabled',
        'context_limit',
        'label',
    ];

    protected $casts = [
        'is_enabled'    => 'boolean',
        'sort_order'    => 'integer',
        'context_limit' => 'integer',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    public function voicePreset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'voice_preset_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Order by sort_order ascending.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Only enabled configs.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }
}

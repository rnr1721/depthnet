<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Memory item model for storing individual memory entries
 *
 * @property int $id
 * @property int $preset_id
 * @property string $content
 * @property int $position
 * @property array|null $metadata
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 * @property AiPreset $preset
 */
class MemoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'preset_id',
        'content',
        'position',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Preset that owns this memory item
     */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    /**
     * Scope to order by position
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }

    /**
     * Scope to filter by preset
     */
    public function scopeForPreset($query, int $presetId)
    {
        return $query->where('preset_id', $presetId);
    }

    /**
     * Get content length
     */
    public function getContentLengthAttribute(): int
    {
        return strlen($this->content);
    }
}

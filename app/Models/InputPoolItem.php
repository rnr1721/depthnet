<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InputPoolItem extends Model
{
    use HasFactory;

    protected $table = 'input_pool_items';

    protected $fillable = [
        'preset_id',
        'source_name',
        'content',
    ];

    /**
     * Preset this pool item belongs to
     */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    /**
     * Scope by preset
     */
    public function scopeForPreset($query, int $presetId)
    {
        return $query->where('preset_id', $presetId);
    }
}

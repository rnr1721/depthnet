<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PresetKnownSource extends Model
{
    use HasFactory;

    protected $table = 'preset_known_sources';

    protected $fillable = [
        'preset_id',
        'source_name',
        'label',
        'description',
        'default_value',
        'sort_order',
    ];

    /**
     * Preset this known source belongs to
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
        return $query->where('preset_id', $presetId)->orderBy('sort_order')->orderBy('id');
    }

    public function getSourceName(): string
    {
        return $this->source_name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}

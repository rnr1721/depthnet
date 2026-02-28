<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goal model
 *
 * @property int $id
 * @property int $preset_id
 * @property string $title
 * @property string|null $motivation
 * @property string $status
 * @property int $position
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class Goal extends Model
{
    use HasFactory;

    protected $fillable = [
        'preset_id',
        'title',
        'motivation',
        'status',
        'position'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(GoalProgress::class)->orderBy('created_at');
    }

    public function scopeForPreset($query, int $presetId)
    {
        return $query->where('preset_id', $presetId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }
}

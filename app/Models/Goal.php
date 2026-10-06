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
 * @property \Illuminate\Support\Carbon|null $focused_at  Non-null = this goal is in focus (max one per preset)
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class Goal extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE  = 'active';
    public const STATUS_PAUSED  = 'paused';
    public const STATUS_DONE    = 'done';
    public const STATUS_DROPPED = 'dropped';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_DONE,
        self::STATUS_DROPPED,
    ];

    /** Statuses a goal can never be focused from (closed for good). */
    public const CLOSED_STATUSES = [
        self::STATUS_DONE,
        self::STATUS_DROPPED,
    ];

    protected $fillable = [
        'preset_id',
        'title',
        'motivation',
        'status',
        'position',
        'focused_at',
    ];

    protected $casts = [
        'focused_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeFocused($query)
    {
        return $query->whereNotNull('focused_at');
    }

    /**
     * Display order. id is a tie-breaker so numbering is deterministic even if
     * two rows ever share a position.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function isFocused(): bool
    {
        return $this->focused_at !== null;
    }
}

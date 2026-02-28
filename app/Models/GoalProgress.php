<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GoalProgress model
 *
 * @property int $id
 * @property int $goal_id
 * @property string $content
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class GoalProgress extends Model
{
    use HasFactory;

    protected $fillable = [
        'goal_id',
        'content'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Skill extends Model
{
    protected $table = 'agent_skills';

    protected $fillable = [
        'preset_id',
        'number',
        'title',
        'description',
    ];

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SkillItem::class, 'skill_id')->orderBy('number');
    }
}

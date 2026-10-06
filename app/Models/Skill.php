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
        'tools',
    ];

    protected $casts = [
        // Tool/plugin names this skill un-hides when loaded (lazy-skills feature).
        // NULL in the DB casts to null; getToolNames() normalizes to [].
        'tools' => 'array',
    ];

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SkillItem::class, 'skill_id')->orderBy('number');
    }

    /**
     * The tool/plugin names attached to this skill for lazy-loading.
     *
     * A pure-knowledge skill returns [] — loading it injects only its content,
     * with no tools riding along. The returned names are NOT validated against
     * the live plugin registry here; that intersection is done in SkillToolGate /
     * SkillLoadService (name self-defense), so a renamed/deleted plugin left in
     * this list is simply ignored downstream, never cleaned destructively here.
     *
     * @return string[]
     */
    public function getToolNames(): array
    {
        $tools = $this->tools ?? [];

        // Defensive: the column is json but a hand-edited row could hold a scalar.
        if (!is_array($tools)) {
            return [];
        }

        // Normalize to a clean list of non-empty string names.
        return array_values(array_filter(
            array_map(
                fn ($t) => is_string($t) ? trim($t) : '',
                $tools
            ),
            fn ($t) => $t !== ''
        ));
    }
}

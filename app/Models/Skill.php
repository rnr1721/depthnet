<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Skill extends Model
{
    /** Created inside DepthNet (admin UI, agent via skill plugin, knowledge). */
    public const SOURCE_DB = 'db';

    /** Imported from the library: {sandbox.shared_path}/skills/{source_path}/SKILL.md */
    public const SOURCE_FILE = 'file';

    protected $table = 'agent_skills';

    protected $fillable = [
        'preset_id',
        'number',
        'title',
        'description',
        'tools',
        'source',
        'source_path',
        'source_hash',
        'source_meta',
        'is_experience',
    ];

    protected $casts = [
        // Tool/plugin names this skill un-hides when loaded (lazy-skills feature).
        // NULL in the DB casts to null; getToolNames() normalizes to [].
        'tools'         => 'array',
        'source_meta'   => 'array',
        'is_experience' => 'boolean',
    ];

    /**
     * In-memory defaults so a freshly created model reports the same values the
     * DB defaults would, without a refresh() round-trip.
     */
    protected $attributes = [
        'source'        => self::SOURCE_DB,
        'is_experience' => false,
    ];

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SkillItem::class, 'skill_id')->orderBy('number');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Skills imported from the library (guest skills). */
    public function scopeFromLibrary(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_FILE);
    }

    /** Skills created inside DepthNet. */
    public function scopeNative(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_DB);
    }

    /** Agent-learned skills — the ones allowed to reach RAG. */
    public function scopeExperience(Builder $query): Builder
    {
        return $query->where('is_experience', true);
    }

    // ── Provenance ────────────────────────────────────────────────────────────

    /** Imported from a SKILL.md in the library. */
    public function isFromFile(): bool
    {
        return $this->source === self::SOURCE_FILE;
    }

    /**
     * Title, description and items of a guest skill mirror its SKILL.md — they are
     * re-cut on re-import, so local edits would be silently lost. tools and
     * is_experience are DepthNet-only fields and stay editable.
     */
    public function isContentReadOnly(): bool
    {
        return $this->isFromFile();
    }

    /** Library dir carries more than SKILL.md (scripts/, references/, assets/ …). */
    public function hasBundledFiles(): bool
    {
        return $this->isFromFile() && !empty($this->source_meta['has_files']);
    }

    /** Library dir carries a scripts/ folder — something to sync into a sandbox. */
    public function hasScripts(): bool
    {
        return $this->isFromFile() && !empty($this->source_meta['has_scripts']);
    }

    public function isExperience(): bool
    {
        return (bool) $this->is_experience;
    }

    // ── Tools ─────────────────────────────────────────────────────────────────

    /**
     * The tool/plugin names attached to this skill for lazy-loading.
     *
     * A pure-knowledge skill returns [] — loading it injects only its content,
     * with no tools riding along. The returned names are NOT validated against
     * the live plugin registry here; that intersection is done in SkillToolGate /
     * SkillLoadService (name self-defense), so a renamed/deleted plugin left in
     * this list is simply ignored downstream, never cleaned destructively here.
     *
     * NOTE: these are DepthNet visibility tools, NOT the spec's `allowed-tools`
     * (host permission). The two are never mapped onto each other; allowed-tools
     * is kept verbatim in source_meta.
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

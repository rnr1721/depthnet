<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PresetPrompt extends Model
{
    use HasFactory;

    /**
     * Context-mode roles a prompt can play in automatic mode switching.
     * Orthogonal to is_active (which is "in use now"); this is "which mode
     * this prompt is meant for". NONE = outside the mechanism (default).
     */
    public const MODE_NONE     = 'none';
    public const MODE_NORMAL   = 'normal';
    public const MODE_EXTENDED = 'extended';

    public const CONTEXT_MODES = [
        self::MODE_NONE,
        self::MODE_NORMAL,
        self::MODE_EXTENDED,
    ];

    protected $table = 'preset_prompts';

    protected $fillable = [
        'preset_id',
        'code',
        'context_mode',
        'content',
        'description',
    ];

    protected $casts = [
        'preset_id'  => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'context_mode' => self::MODE_NONE,
    ];

    // ─── Relations ───────────────────────────────────────────────────────────────

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    // ─── Accessors ───────────────────────────────────────────────────────────────

    public function getId(): int
    {
        return $this->id;
    }

    public function getPresetId(): int
    {
        return $this->preset_id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getContent(): string
    {
        return $this->content ?? '';
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Version history for this prompt, newest first.
     * Insert-only: each edit appends a snapshot. See PresetPromptVersion.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PresetPromptVersion::class, 'prompt_id')
            ->orderByDesc('version');
    }

    /**
     * The latest version number for this prompt, or 0 if none yet.
     * Used to compute the next version number (MAX+1) when appending.
     */
    public function latestVersionNumber(): int
    {
        return (int) $this->versions()->max('version');
    }

    /**
     * The context mode this prompt is assigned to, or 'none'.
     */
    public function getContextMode(): string
    {
        return $this->context_mode ?? self::MODE_NONE;
    }

    /**
     * Whether this prompt participates in automatic mode switching at all.
     */
    public function isModeAware(): bool
    {
        return $this->getContextMode() !== self::MODE_NONE;
    }

    /**
     * Scope: the prompt of a preset assigned to a given context mode.
     * (Invariant guarantees at most one per non-'none' mode.)
     */
    public function scopeForContextMode($query, string $mode)
    {
        return $query->where('context_mode', $mode);
    }

}

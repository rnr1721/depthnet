<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PresetPrompt extends Model
{
    use HasFactory;

    protected $table = 'preset_prompts';

    protected $fillable = [
        'preset_id',
        'code',
        'content',
        'description',
    ];

    protected $casts = [
        'preset_id'  => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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

}

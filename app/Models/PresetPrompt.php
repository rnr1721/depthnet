<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}

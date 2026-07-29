<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PresetPromptVersion — an immutable snapshot of a PresetPrompt's content.
 *
 * One row per edit. Written inside the same transaction as the edit that
 * produced it (see PresetPromptService). Never updated, never deleted except
 * by cascade when the owning prompt is removed.
 *
 * Actor semantics (edited_by):
 *   'agent'  — the model edited its own prompt
 *   'human'  — edited from the admin UI (editor_user_id points to the user)
 *   'system' — seeding / migration / automated
 */
class PresetPromptVersion extends Model
{
    use HasFactory;

    protected $table = 'preset_prompt_versions';

    // These are historical snapshots — nothing mutates them after insert.
    // No mass-assignment guard footguns: we only ever create, never update.
    protected $fillable = [
        'prompt_id',
        'version',
        'content',
        'edit_summary',
        'edited_by',
        'editor_user_id',
    ];

    protected $casts = [
        'prompt_id'      => 'integer',
        'version'        => 'integer',
        'editor_user_id' => 'integer',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    // Actor-class constants — use these instead of raw strings at call sites.
    public const BY_AGENT  = 'agent';
    public const BY_HUMAN  = 'human';
    public const BY_SYSTEM = 'system';

    // ─── Relations ───────────────────────────────────────────────────────────────

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(PresetPrompt::class, 'prompt_id');
    }

    /**
     * The concrete human editor, when edited_by = 'human'.
     * Null for agent/system versions, or if the user was later deleted.
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_user_id');
    }

    // ─── Accessors ───────────────────────────────────────────────────────────────

    public function getId(): int
    {
        return $this->id;
    }

    public function getPromptId(): int
    {
        return $this->prompt_id;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getContent(): string
    {
        return $this->content ?? '';
    }

    public function getEditSummary(): ?string
    {
        return $this->edit_summary;
    }

    public function getEditedBy(): string
    {
        return $this->edited_by;
    }

    public function getEditorUserId(): ?int
    {
        return $this->editor_user_id;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    public function isByAgent(): bool
    {
        return $this->edited_by === self::BY_AGENT;
    }

    public function isByHuman(): bool
    {
        return $this->edited_by === self::BY_HUMAN;
    }

    public function isBySystem(): bool
    {
        return $this->edited_by === self::BY_SYSTEM;
    }
}

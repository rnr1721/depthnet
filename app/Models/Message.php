<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    /**
     * Message origin markers, stored in metadata['source'].
     *
     * Describes WHO woke the cycle that this message initiated — not the
     * content of the message. Read by AgentActionsHandler::determineTurnNeed()
     * to decide whether a cycle runs in orchestrated-pipeline mode (self-continue
     * until the role's task leaves IN_PROGRESS) or in normal mode (turn_trigger
     * as configured — covers chat debugging, voice, API).
     *
     * Absence of a source marker means "normal mode" — the safe default. New
     * entry points should set their own source; an unmarked message will never
     * accidentally enter pipeline mode.
     */
    public const SOURCE_WEB          = 'web';
    public const SOURCE_ORCHESTRATOR = 'orchestrator';
    public const SOURCE_RHASSPY      = 'rhasspy';
    public const SOURCE_API          = 'api';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'role',
        'content',
        'from_user_id',
        'preset_id',
        'is_visible_to_user',
        'metadata',
        'system_prompt'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'is_visible_to_user' => 'boolean',
    ];

    /**
     * Get the user who sent the message (if any)
     */
    public function fromUser()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /**
     * Get the AI preset this message belongs to
     */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    /**
     * Scope messages by preset
     */
    public function scopeForPreset($query, int $presetId)
    {
        return $query->where('preset_id', $presetId);
    }

    /**
     * Origin marker of this message, or null if unmarked.
     * Null means "normal mode" — the safe default.
     */
    public function getSource(): ?string
    {
        return $this->metadata['source'] ?? null;
    }
}

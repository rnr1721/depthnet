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
     *
     * SOURCE_COMPACTION marks the recap message written after a range of history
     * is folded away. It is NOT an external input — it is the agent's own
     * consolidated memory of what fell out of the window, re-presented in the
     * user slot (first person) so the model reads it as "here is what came
     * before". Because it is not the orchestrator marker, cycleWasOrchestrated()
     * returns false for it and the recap never drags a cycle into pipeline mode —
     * it just sits first in the fresh window.
     */
    public const SOURCE_WEB          = 'web';
    public const SOURCE_ORCHESTRATOR = 'orchestrator';
    public const SOURCE_RHASSPY      = 'rhasspy';
    public const SOURCE_API          = 'api';
    public const SOURCE_COMPACTION   = 'compaction';
    public const SOURCE_SKILL        = 'skill';

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
        'compacted',
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
        'compacted' => 'boolean',
    ];

    /**
     * Default attribute values.
     *
     * compacted defaults to false so any message created without an explicit
     * value lands in the active window — the safe default (never accidentally
     * hidden from the agent).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'compacted' => false,
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
     * Scope: only messages in the active context window (not folded away).
     *
     * This is the window the agent actually thinks in. Context builders apply
     * this; RAG does NOT — RAG reads journal/vector (separate tables), so folded
     * messages stay reachable there. That split is what makes compaction a
     * visibility change, not a memory loss.
     */
    public function scopeActiveWindow($query)
    {
        return $query->where('compacted', false);
    }

    /**
     * Origin marker of this message, or null if unmarked.
     * Null means "normal mode" — the safe default.
     */
    public function getSource(): ?string
    {
        return $this->metadata['source'] ?? null;
    }

    /**
     * Whether this message is a compaction recap — the first-person summary
     * written when a range of history was folded away.
     */
    public function isRecap(): bool
    {
        return $this->getSource() === self::SOURCE_COMPACTION;
    }

    /**
     * The id range this recap folded, as ['from_id' => int, 'to_id' => int],
     * or null when absent (non-recap messages, or recaps written before the
     * range was recorded).
     *
     * Kept in metadata['compaction'] rather than dedicated columns: the range is
     * only ever read for a recap row, so it costs nothing in the schema and rides
     * along in the json that already exists. Enables a future "unfold / show the
     * original" path without touching the table.
     */
    public function getCompactedRange(): ?array
    {
        $range = $this->metadata['compaction'] ?? null;

        if (!is_array($range) || !isset($range['from_id'], $range['to_id'])) {
            return null;
        }

        return [
            'from_id' => (int) $range['from_id'],
            'to_id'   => (int) $range['to_id'],
        ];
    }

}

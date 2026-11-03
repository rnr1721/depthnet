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
    public const SOURCE_API          = 'api';
    public const SOURCE_COMPACTION   = 'compaction';
    public const SOURCE_SKILL        = 'skill';
    public const SOURCE_GOAL         = 'goal_focus';
    public const SOURCE_MESSENGER    = 'messenger';
    public const SOURCE_AGENT        = 'agent';

    /**
     * Message kind markers, stored in metadata['kind'].
     *
     * A second axis, orthogonal to source. Source answers "who / which mode"
     * and is deliberately INHERITED (the orchestrated self-continue carries
     * SOURCE_ORCHESTRATOR so the pipeline stays in pipeline mode). Kind answers
     * "is this a new input, or the agent's own continuation?" and is never
     * inherited. The context window uses it to find episode boundaries.
     *
     *   KIND_INPUT    — something external arrived: user, API, messenger, handoff,
     *                   orchestrator wake, a pool payload with at least one real
     *                   (non-synthetic) source. Starts a new episode.
     *   KIND_CONTINUE — self-generated continuation: "Continue", cycle start /
     *                   continue instructions, cycle-prompt impulses, a pool
     *                   payload made only of synthetic sources. Never starts one.
     *   KIND_RECAP    — a compaction recap. Starts a (synthetic) episode.
     *
     * Absence of a kind marker means KIND_INPUT. A forgotten entry point then
     * only makes episodes smaller than ideal — the old behaviour. The opposite
     * default would silently glue someone's new question onto the previous
     * episode.
     */
    public const KIND_INPUT    = 'input';
    public const KIND_CONTINUE = 'continue';
    public const KIND_RECAP    = 'recap';

    /**
     * Non-user kinds:
     *   KIND_SPEECH   — the system row carrying what the agent said outward
     *                   (speak / plain-text answer). metadata['agent_message_id']
     *                   points at the agent row of that cycle. Read by the
     *                   context window to build the outline of older episodes.
     *   KIND_OUTLINE  — an in-memory user-role row the context window builds
     *                   to list older episodes it does not show in full.
     *                   Never persisted.
     */
    public const KIND_SPEECH   = 'speech';
    public const KIND_OUTLINE  = 'outline';

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
        return $this->getSource() === self::SOURCE_COMPACTION
            || ($this->metadata['kind'] ?? null) === self::KIND_RECAP;
    }

    /**
     * Kind marker of this message. Unmarked means KIND_INPUT (see constants).
     */
    public function getKind(): string
    {
        $kind = $this->metadata['kind'] ?? null;

        return is_string($kind) && $kind !== '' ? $kind : self::KIND_INPUT;
    }

    /**
     * Whether this message opens a new episode: a user-role row that is not
     * the agent's own continuation. Recaps count — they open the window after
     * a fold.
     */
    public function isEpisodeHead(): bool
    {
        return $this->role === 'user' && $this->getKind() !== self::KIND_CONTINUE;
    }

    /**
     * Whether this agent row carried speech to the outside (a speak result or a
     * plain-text answer). Set by AgentActionsHandler on both persistence paths;
     * a cycle that spoke closes the episode it belongs to.
     */
    public function didSpeak(): bool
    {
        return $this->role !== 'user'
            && !empty($this->metadata['content_duplicated_in_system']);
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

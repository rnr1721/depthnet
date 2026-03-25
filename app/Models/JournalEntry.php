<?php

namespace App\Models;

use App\Contracts\Agent\Plugins\TfIdfDocumentInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * JournalEntry Model
 *
 * Represents a single episodic memory entry — a structured event
 * in the agent's chronicle. Unlike VectorMemory (semantic insights),
 * journal entries are chronological events with type and outcome.
 *
 * @property int         $id
 * @property int         $preset_id
 * @property \Carbon\Carbon $recorded_at
 * @property string      $type         action|reflection|decision|error|observation|interaction
 * @property string      $summary      Short description (always visible)
 * @property string|null $details      Full event text (loaded on demand)
 * @property string|null $outcome      success|failure|pending|null
 * @property array       $tfidf_vector Semantic search vector
 */
class JournalEntry extends Model implements TfIdfDocumentInterface
{
    protected $table = 'agent_journal';

    protected $fillable = [
        'preset_id',
        'recorded_at',
        'type',
        'summary',
        'details',
        'outcome',
        'tfidf_vector',
    ];

    protected $casts = [
        'recorded_at'  => 'datetime',
        'tfidf_vector' => 'array',
    ];

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForPreset($query, int $presetId)
    {
        return $query->where('preset_id', $presetId);
    }

    public function scopeRecent($query, int $limit = 10)
    {
        return $query->orderBy('recorded_at', 'desc')->limit($limit);
    }

    public function scopeOnDate($query, \Carbon\Carbon $date)
    {
        return $query->whereDate('recorded_at', $date->toDateString());
    }

    public function scopeBetween($query, \Carbon\Carbon $from, \Carbon\Carbon $to)
    {
        return $query->whereBetween('recorded_at', [$from, $to]);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeWithOutcome($query, string $outcome)
    {
        return $query->where('outcome', $outcome);
    }

    // -------------------------------------------------------------------------
    // TfIdfDocumentInterface
    // -------------------------------------------------------------------------

    public function getTfIdfVector(): array
    {
        return $this->tfidf_vector ?? [];
    }

    public function getTextContent(): string
    {
        // Index both summary and details for richer semantic search
        return $this->summary . ($this->details ? "\n" . $this->details : '');
    }

    public function getCreatedAt(): ?\Carbon\Carbon
    {
        return $this->recorded_at;
    }
}

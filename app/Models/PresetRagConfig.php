<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PresetRagConfig
 *
 * Represents one RAG pipeline slot attached to a main preset.
 * A preset can have multiple configs, each with its own RAG preset,
 * source selection, and search tuning. They are executed in sort_order
 * and their results are merged into a single [[rag_context]] block.
 *
 * Only the config with is_primary = true participates in RagQueryPlugin
 * (agent-provided multi-queries). Secondary configs always use
 * model-formulated queries.
 *
 * Sources recognised by RagContextEnricher:
 *   vector_memory — flat + associative vector search (mode controlled by rag_mode)
 *   journal       — JournalService semantic search
 *   skills        — SkillService search
 *   persons       — PersonContextEnricher (Heart-aware person facts)
 *
 * @property int         $id
 * @property int         $preset_id
 * @property int         $rag_preset_id
 * @property int         $sort_order
 * @property bool        $is_primary
 * @property array|null  $sources
 * @property string      $rag_mode
 * @property string      $rag_engine
 * @property int         $rag_context_limit
 * @property int         $rag_results
 * @property int         $rag_journal_limit
 * @property int         $rag_skills_limit
 * @property int         $rag_content_limit
 * @property int         $rag_journal_context_window
 * @property bool        $rag_relative_dates
 */
class PresetRagConfig extends Model
{
    protected $table = 'preset_rag_configs';

    protected $fillable = [
        'preset_id',
        'rag_preset_id',
        'sort_order',
        'is_primary',
        'sources',
        'rag_mode',
        'rag_engine',
        'rag_context_limit',
        'rag_results',
        'rag_journal_limit',
        'rag_skills_limit',
        'rag_content_limit',
        'rag_journal_context_window',
        'rag_relative_dates',
    ];

    protected $casts = [
        'is_primary'                 => 'boolean',
        'rag_relative_dates'         => 'boolean',
        'sources'                    => 'array',
        'sort_order'                 => 'integer',
        'rag_context_limit'          => 'integer',
        'rag_results'                => 'integer',
        'rag_journal_limit'          => 'integer',
        'rag_skills_limit'           => 'integer',
        'rag_content_limit'          => 'integer',
        'rag_journal_context_window' => 'integer',
    ];

    protected $attributes = [
        'sort_order'                 => 0,
        'is_primary'                 => false,
        'rag_mode'                   => 'flat',
        'rag_engine'                 => 'tfidf',
        'rag_context_limit'          => 5,
        'rag_results'                => 5,
        'rag_journal_limit'          => 3,
        'rag_skills_limit'           => 3,
        'rag_content_limit'          => 400,
        'rag_journal_context_window' => 0,
        'rag_relative_dates'         => false,
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * The preset this config belongs to.
     */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    /**
     * The RAG preset used for query formulation and vector memory.
     */
    public function ragPreset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'rag_preset_id');
    }

    // ── Source helpers ────────────────────────────────────────────────────────

    /**
     * Whether this config searches vector memory (flat or associative).
     */
    public function hasSource(string $source): bool
    {
        return in_array($source, $this->sources ?? [], true);
    }

    public function hasVectorMemory(): bool
    {
        return $this->hasSource('vector_memory');
    }

    public function hasJournal(): bool
    {
        return $this->hasSource('journal');
    }

    public function hasSkills(): bool
    {
        return $this->hasSource('skills');
    }

    public function hasPersons(): bool
    {
        return $this->hasSource('persons');
    }

    public function hasFiles(): bool
    {
        return $this->hasSource('files');
    }

    public function hasOntology(): bool
    {
        return $this->hasSource('ontology');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getRagMode(): string
    {
        return $this->rag_mode;
    }

    public function getRagEngine(): string
    {
        return $this->rag_engine;
    }

    public function getRagContextLimit(): int
    {
        return $this->rag_context_limit;
    }

    public function getRagResults(): int
    {
        return $this->rag_results;
    }

    public function getRagJournalLimit(): int
    {
        return $this->rag_journal_limit;
    }

    public function getRagSkillsLimit(): int
    {
        return $this->rag_skills_limit;
    }

    public function getRagContentLimit(): int
    {
        return $this->rag_content_limit;
    }

    public function getRagJournalContextWindow(): int
    {
        return $this->rag_journal_context_window;
    }

    public function getRagRelativeDates(): bool
    {
        return $this->rag_relative_dates;
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Ordered by sort_order for pipeline execution.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Only the primary config.
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }
}

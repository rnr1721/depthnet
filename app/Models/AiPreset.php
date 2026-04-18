<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiPreset extends Model
{
    use HasFactory;

    protected $table = "ai_presets";

    protected $fillable = [
        'name',
        'description',
        'engine_name',
        'active_prompt_id',
        'input_mode',
        'preset_code',
        'preset_code_next',
        'pre_run_commands',
        'rag_preset_id',
        'rag_context_limit',
        'rag_results',
        'rag_mode',
        'rag_engine',
        'rag_relative_dates',
        'rag_journal_limit',
        'rag_skills_limit',
        'rag_content_limit',
        'rag_journal_context_window',
        'defrag_enabled',
        'defrag_prompt',
        'defrag_keep_per_day',
        'voice_preset_id',
        'voice_context_limit',
        'cycle_prompt_preset_id',
        'cp_context_limit',
        'voice_mp_commands',
        'default_call_message',
        'before_execution_wait',
        'plugins_disabled',
        'engine_config',
        'metadata',
        'loop_interval',
        'max_context_limit',
        'agent_result_mode',
        'error_behavior',
        'allow_handoff_to',
        'allow_handoff_from',
        'rhasspy_enabled',
        'rhasspy_url',
        'rhasspy_tts_voice',
        'rhasspy_incoming_enabled',
        'rhasspy_incoming_token',
        'is_active',
        'is_default',
        'created_by',
    ];

    protected $casts = [
        'engine_config' => 'array',
        'metadata' => 'array',
        'loop_interval' => 'integer',
        'active_prompt_id' => 'integer',
        'rag_preset_id' => 'integer',
        'rag_context_limit' => 'integer',
        'rag_results' => 'integer',
        'rag_relative_dates'          => 'boolean',
        'rag_journal_limit'           => 'integer',
        'rag_skills_limit'            => 'integer',
        'rag_content_limit'           => 'integer',
        'rag_journal_context_window'  => 'integer',
        'defrag_enabled'      => 'boolean',
        'defrag_keep_per_day' => 'integer',
        'voice_preset_id' => 'integer',
        'voice_context_limit' => 'integer',
        'cycle_prompt_preset_id' => 'integer',
        'cp_context_limit' => 'integer',
        'max_context_limit' => 'integer',
        'before_execution_wait' => 'integer',
        'allow_handoff_to' => 'boolean',
        'allow_handoff_from' => 'boolean',
        'rhasspy_enabled'          => 'boolean',
        'rhasspy_incoming_enabled' => 'boolean',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'input_mode' => 'single',
        'is_active' => true,
        'is_default' => false,
        'agent_result_mode' => 'internal',
        'allow_handoff_to' => true,
        'allow_handoff_from' => true,
        'error_behavior' => 'stop',
        'before_execution_wait' => 5,
        'engine_config' => '{}',
        'metadata' => '{}',
        'plugins_disabled' => '',
        'rag_context_limit' => 5,
        'rag_results' => 5,
        'rag_mode' => 'flat',
        'rag_engine' => 'tfidf',
        'rag_relative_dates'          => false,
        'rag_journal_limit'           => 3,
        'rag_skills_limit'            => 3,
        'rag_content_limit'           => 400,
        'rag_journal_context_window'  => 0,
        'voice_context_limit' => 4,
        'cp_context_limit' => 5,
        'voice_mp_commands' => '',
        'pre_run_commands' => '',
        'rhasspy_enabled'          => false,
        'rhasspy_incoming_enabled' => false,
    ];

    /**
     * Known sources for pool input mode.
     * These sources are routed to the system prompt via [[known_sources]]
     * instead of the regular JSON payload.
     */
    public function knownSources(): HasMany
    {
        return $this->hasMany(PresetKnownSource::class, 'preset_id');
    }

    /**
     * Whether this preset has any known sources configured.
     */
    public function hasKnownSources(): bool
    {
        return $this->knownSources()->exists();
    }

    /**
     * All prompts for this preset
     */
    public function prompts(): HasMany
    {
        return $this->hasMany(PresetPrompt::class, 'preset_id')->orderBy('created_at', 'asc');
    }

    /**
     * Current active prompt
     */
    public function activePrompt(): BelongsTo
    {
        return $this->belongsTo(PresetPrompt::class, 'active_prompt_id');
    }

    /**
     * User who created this preset
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Messages associated with this preset
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'preset_id')->orderBy('created_at', 'asc');
    }

    /**
     * Get enabled plugin configurations for this preset
     */
    public function enabledPluginConfigurations(): HasMany
    {
        return $this->hasMany(PresetPluginConfig::class, 'preset_id')->where('is_enabled', true);
    }

    /**
     * Plugin configurations associated with this preset (normalized approach)
     */
    public function pluginConfigurations(): HasMany
    {
        return $this->hasMany(PresetPluginConfig::class, 'preset_id');
    }

    /**
     * RAG preset: if set, this preset will receive associative memory
     * context from the RAG preset's vector memory before each thinking cycle.
     */
    public function ragPreset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'rag_preset_id');
    }

    /**
     * Whether a dynamic cycle prompt is enabled for this preset.
     * True when cycle_prompt_preset_id is set.
     */
    public function hasCyclePrompt(): bool
    {
        return !is_null($this->cycle_prompt_preset_id);
    }

    /**
     * Cycle prompt preset: if set, this preset will use another preset to generate
     * a dynamic continuation prompt instead of the static "[Continue your thinking cycle]".
     * Useful for breaking resonance loops with critics, motivators, provocateurs etc.
     */
    public function cyclePromptPreset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'cycle_prompt_preset_id');
    }

    /**
     * Boot method to handle default preset logic
     */
    protected static function boot()
    {
        parent::boot();
        static::saving(function ($preset) {
            if ($preset->is_default && $preset->isDirty('is_default')) {
                static::where('is_default', true)
                    ->where('id', '!=', $preset->id)
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * Whether RAG enrichment is enabled for this preset.
     * True when rag_preset_id is set and points to an existing preset.
     */
    public function hasRag(): bool
    {
        return !is_null($this->rag_preset_id);
    }

    /**
     * Voice preset: if set, this preset will receive hints from another preset that is optimized for voice interactions
     */
    public function voicePreset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'voice_preset_id');
    }

    /**
     * Whether Internal Voice enrichment is enabled for this preset.
     * True when voice_preset_id is set and points to an existing preset.
     */
    public function hasVoice(): bool
    {
        return !is_null($this->voice_preset_id);
    }

    public function commandResults(): HasMany
    {
        return $this->hasMany(PresetCommandResult::class, 'preset_id')
                    ->orderBy('created_at', 'asc');
    }

    /**
     * Get ID of the preset
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get name of the preset
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get description of the preset
     *
     * @return string|null
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Get active system prompt for this preset
     * Compatible with the previous interface - all agent code continues to work.
     *
     * @return string
     */
    public function getSystemPrompt(): string
    {
        // If the relation is already loaded, we use it, otherwise we make a request.
        if ($this->relationLoaded('activePrompt')) {
            return $this->activePrompt?->getContent() ?? '';
        }

        return $this->activePrompt()->value('content') ?? '';
    }

    /**
     * Toggles the active prompt by code.
     * Used by the agent team.
     *
     * @throws \InvalidArgumentException if a prompt with this code is not found
     */
    public function switchPrompt(string $code): bool
    {
        $prompt = $this->prompts()->where('code', $code)->first();

        if (!$prompt) {
            throw new \InvalidArgumentException(
                "Prompt with code '{$code}' not found for preset '{$this->name}'"
            );
        }

        $this->active_prompt_id = $prompt->getId();
        return $this->save();
    }

    /**
     * Returns all available prompt codes for this preset.
     * Used by the agent to make selections when switching.
     */
    public function getAvailablePromptCodes(): array
    {
        return $this->prompts()->pluck('code')->toArray();
    }

    /**
     * Get input mode for this preset (single or pool)
     * Multiple input sources or classicl single input
     *
     * @return string
     */
    public function getInputMode(): string
    {
        return $this->input_mode;
    }

    public function getPluginsDisabled(): string
    {
        return $this->plugins_disabled ?? '';
    }

    /**
     * Get engine name for this preset
     *
     * @return string
     */
    public function getEngineName(): string
    {
        return $this->engine_name;
    }

    /**
     * Get metadata for this preset
     *
     * @return array
     */
    public function getEngineConfig(): array
    {
        return $this->engine_config ?? [];
    }

    /**
     * Get Loop interval between cycles for this preset
     *
     * @return array
     */
    public function getLoopInterval(): int
    {
        return $this->loop_interval;
    }

    /**
     * Get maximum context limit (messages) for this preset
     *
     * @return int
     */
    public function getMaxContextLimit(): int
    {
        return $this->max_context_limit;
    }

    /**
     * Context limit for RAG
     *
     * @return integer
     */
    public function getRagContextLimit(): int
    {
        return $this->rag_context_limit;
    }

    /**
     * Get number of RAG results to give LLM
     *
     * @return integer
     */
    public function getRagResults(): int
    {
        return $this->rag_results;
    }

    /**
     * Rag mode - flat or associative
     *
     * @return string
     */
    public function getRagMode(): string
    {
        return $this->rag_mode;
    }

    /**
     * Rag engine - tfidf or embedding (need provider)
     *
     * @return string
     */
    public function getRagEngine(): string
    {
        return $this->rag_engine;
    }

    /**
     * Add relative dates to RAG context in results
     *
     * @return boolean
     */
    public function getRagRelativeDates(): bool
    {
        return $this->rag_relative_dates;
    }

    /**
     * Journal max entries in RAG
     *
     * @return integer
     */
    public function getRagJournalLimit(): int
    {
        return $this->rag_journal_limit;
    }

    /**
     * Skills max entries in RAG
     *
     * @return integer
     */
    public function getRagSkillsLimit(): int
    {
        return $this->rag_skills_limit;
    }

    /**
     * Content (entry) max symbols in RAG
     *
     * @return integer
     */
    public function getRagContentLimit(): int
    {
        return $this->rag_content_limit;
    }

    /**
     * RAG Journal content window (journal neighborhood records)
     *
     * @return integer
     */
    public function getRagJournalContextWindow(): int
    {
        return $this->rag_journal_context_window;
    }

    /**
     * Whether vector memory defragmentation is enabled for this preset.
     *
     * @return bool
     */
    public function getDefragEnabled(): bool
    {
        return $this->defrag_enabled ?? false;
    }

    /**
     * Custom defrag prompt for this preset.
     * Null means the default prompt from data/defrag/default_prompt.txt is used.
     *
     * @return string|null
     */
    public function getDefragPrompt(): ?string
    {
        return $this->defrag_prompt;
    }

    /**
     * Number of distilled summaries to keep per calendar day after defrag.
     *
     * @return int
     */
    public function getDefragKeepPerDay(): int
    {
        return $this->defrag_keep_per_day ?? 3;
    }

    /**
     * Context limit for Inner Voice in single mode
     *
     * @return integer
     */
    public function getVoiceContextLimit(): int
    {
        return $this->voice_context_limit;
    }

    /**
     * Context limit for Inner Voice in loop mode
     *
     * @return integer
     */
    public function getCpContextLimit(): int
    {
        return $this->cp_context_limit;
    }

    /**
     * Get list of commands, that Get a list of commands
     * that should be executed in the main preset space when InnerVoice is executed
     *
     * @return string
     */
    public function getVoiceMpCommands(): string
    {
        return $this->voice_mp_commands ?? '';
    }

    /**
     * Get agent result mode for this preset
     * If the results  are returned as separate messages or as a single response
     *
     * @return string
     */
    public function getAgentResultMode(): string
    {
        return $this->agent_result_mode;
    }

    /**
     * Check if this preset is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if this preset is the current one
     *
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->is_default;
    }

    /**
     * Get ID of the user who created this preset
     *
     * @return int|null
     */
    public function getCreatedBy(): ?int
    {
        return $this->created_by;
    }

    /**
     * Get creation and update timestamps
     *
     * @return \DateTime|null
     */
    public function getCreatedAt(): ?\DateTime
    {
        return $this->created_at;
    }

    /**
     * Get update timestamp
     *
     * @return \DateTime|null
     */
    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updated_at;
    }

    /**
     * Get preset code
     *
     * @return string|null
     */
    public function getPresetCode(): ?string
    {
        return $this->preset_code;
    }

    /**
     * Undocumented get preset code, or if it not available - name
     *
     * @return string
     */
    public function getAvailableName(): string
    {
        return empty($this->getPresetCode()) ? $this->getName() : $this->getPresetCode();
    }

    /**
     * Get preset code for next
     *
     * @return string|null
     */
    public function getPresetCodeNext(): ?string
    {
        return $this->preset_code_next;
    }

    /**
     * Get commands to run before call LLM
     *
     * @return string
     */
    public function getPreRunCommands(): string
    {
        return $this->pre_run_commands ?? '';
    }

    public function getErrorBehavior(): string
    {
        return $this->error_behavior;
    }

    public function getDefaultCallMessage(): ?string
    {
        return $this->default_call_message;
    }

    public function getBeforeExecutionWait(): int
    {
        return $this->before_execution_wait;
    }

    /**
     * Get cycle prompt preset ID
     *
     * @return int|null
     */
    public function getCyclePromptPresetId(): ?int
    {
        return $this->cycle_prompt_preset_id;
    }

    public function allowsHandoffTo(): bool
    {
        return $this->allow_handoff_to;
    }

    public function allowsHandoffFrom(): bool
    {
        return $this->allow_handoff_from;
    }

    /**
     * Scope for active presets
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for default preset
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope by engine
     */
    public function scopeByEngine($query, string $engineName)
    {
        return $query->where('engine_name', $engineName);
    }

    /**
     * Memory items associated with this preset
     */
    public function memoryItems(): HasMany
    {
        return $this->hasMany(MemoryItem::class, 'preset_id')->ordered();
    }

    /**
     * Vector memories associated with this preset
     */
    public function vectorMemories(): HasMany
    {
        return $this->hasMany(VectorMemory::class, 'preset_id');
    }

    public function getRhasspyEnabled(): bool
    {
        return $this->rhasspy_enabled;
    }
    public function getRhasspyUrl(): ?string
    {
        return $this->rhasspy_url;
    }
    public function getRhasspyTtsVoice(): ?string
    {
        return $this->rhasspy_tts_voice;
    }
    public function getRhasspyIncomingEnabled(): bool
    {
        return $this->rhasspy_incoming_enabled;
    }
    public function getRhasspyIncomingToken(): ?string
    {
        return $this->rhasspy_incoming_token;
    }

}

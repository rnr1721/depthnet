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
        'target_preset_id',
        'target_plugins_whitelist',
        'parent_preset_id',
        'is_spawned',
        'name',
        'description',
        'engine_name',
        'active_prompt_id',
        'input_mode',
        'pool_relative_dates',
        'pulse_dates',
        'preset_code',
        'preset_code_next',
        'pre_run_commands',
        'turn_trigger',
        'defrag_enabled',
        'defrag_prompt',
        'defrag_keep_per_day',
        'cycle_prompt_preset_id',
        'compressor_preset_id',
        'compaction_watchdog_limit',
        'knowledge_formulator_preset_id',
        'cp_context_limit',
        'voice_mp_commands',
        'default_call_message',
        'before_execution_wait',
        'plugins_disabled',
        'engine_config',
        'metadata',
        'loop_interval',
        'max_context_limit',
        'max_context_limit_extended',
        'pre_pass_enabled',
        'pre_pass_instruction',
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
        'target_preset_id'           => 'integer',
        'parent_preset_id'           => 'integer',
        'is_spawned'                 => 'boolean',
        'pool_relative_dates'        => 'boolean',
        'pulse_dates'                => 'boolean',
        'engine_config'              => 'array',
        'metadata'                   => 'array',
        'loop_interval'              => 'integer',
        'active_prompt_id'           => 'integer',
        'defrag_enabled'             => 'boolean',
        'defrag_keep_per_day'        => 'integer',
        'cycle_prompt_preset_id'     => 'integer',
        'compressor_preset_id'       => 'integer',
        'compaction_watchdog_limit'  => 'integer',
        'knowledge_formulator_preset_id' => 'integer',
        'cp_context_limit'           => 'integer',
        'max_context_limit'          => 'integer',
        'max_context_limit_extended' => 'integer',
        'pre_pass_enabled'           => 'boolean',
        'before_execution_wait'      => 'integer',
        'allow_handoff_to'           => 'boolean',
        'allow_handoff_from'         => 'boolean',
        'rhasspy_enabled'            => 'boolean',
        'rhasspy_incoming_enabled'   => 'boolean',
        'is_active'                  => 'boolean',
        'is_default'                 => 'boolean',
        'created_at'                 => 'datetime',
        'updated_at'                 => 'datetime',
    ];


    protected $attributes = [
        'target_preset_id'         => null,
        'target_plugins_whitelist' => null,
        'is_spawned'               => false,
        'parent_preset_id'         => null,
        'input_mode'               => 'pool',
        'pool_relative_dates'      => false,
        'pulse_dates'              => false,
        'is_active'                => true,
        'is_default'               => false,
        'agent_result_mode'        => 'tool_calls',
        'allow_handoff_to'         => true,
        'allow_handoff_from'       => true,
        'error_behavior'           => 'stop',
        'before_execution_wait'    => 5,
        'engine_config'            => '{}',
        'metadata'                 => '{}',
        'plugins_disabled'         => '',
        'cp_context_limit'         => 5,
        'voice_mp_commands'        => '',
        'pre_run_commands'         => '',
        'turn_trigger'             => 'no_speak',
        'rhasspy_enabled'          => false,
        'rhasspy_incoming_enabled' => false,
        'pre_pass_enabled'         => false,
    ];

    /**
     * The preset whose plugin context this preset operates in.
     * When set, plugin commands execute against the target preset's
     * data space (memory, journal, etc.) instead of own.
     */
    public function targetPreset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'target_preset_id');
    }

    /**
     * ID of the target preset for cross-preset execution.
     * Null means operate in own context (default behaviour).
     */
    public function getTargetPresetId(): ?int
    {
        return $this->target_preset_id;
    }

    /**
     * Raw whitelist string, e.g. "memory,journal,vector_memory".
     * Null means no cross-preset execution is configured.
     */
    public function getTargetPluginsWhitelist(): ?string
    {
        return $this->target_plugins_whitelist;
    }

    /**
     * Parsed whitelist as array.
     * Returns empty array when null — no plugins whitelisted.
     */
    public function getTargetPluginsWhitelistArray(): array
    {
        if (empty($this->target_plugins_whitelist)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map('trim', explode(',', $this->target_plugins_whitelist))
            )
        );
    }


    /**
     * Parent preset that spawned this one.
     * Null for regular (non-spawned) presets.
     */
    public function parentPreset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'parent_preset_id');
    }

    /**
     * Ephemeral child presets spawned by this preset.
     */
    public function spawnedPresets(): HasMany
    {
        return $this->hasMany(AiPreset::class, 'parent_preset_id');
    }

    /**
     * Skills belonging to this preset (lazy-skills feature; also useful for the
     * future transparency log). The loading mechanism is active for this preset
     * iff some of these skills declare tools — there is no separate enable flag.
     */
    public function skills(): HasMany
    {
        return $this->hasMany(Skill::class, 'preset_id');
    }

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
     * Attachment files (documents), related to preset
     *
     * @return HasMany
     */
    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'preset_id');
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
     * InnerVoice pipeline configs for this preset, ordered for execution
     *
     * @return HasMany
     */
    public function innerVoiceConfigs(): HasMany
    {
        return $this->hasMany(PresetInnerVoiceConfig::class, 'preset_id');
    }

    /**
     * RAG pipeline configs for this preset, ordered for execution.
     * Each config points to a RAG preset and carries its own search settings.
     */
    public function ragConfigs(): HasMany
    {
        return $this->hasMany(PresetRagConfig::class, 'preset_id')->ordered();
    }

    /**
     * Whether this preset has at least one RAG config.
     */
    public function hasRag(): bool
    {
        return $this->ragConfigs()->exists();
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
     * Get ID of the preset
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Whether this preset was created by SpawnPlugin at runtime.
     */
    public function isSpawned(): bool
    {
        return $this->is_spawned;
    }

    /**
     * ID of the parent preset, or null if this is a regular preset.
     */
    public function getParentPresetId(): ?int
    {
        return $this->parent_preset_id;
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

    public function getPoolRelativeDates(): bool
    {
        return $this->pool_relative_dates;
    }

    public function getPulseDates(): bool
    {
        return $this->pulse_dates;
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
     * Context limit for extended (work) mode.
     * Null means the feature is off — resolver falls back to max_context_limit.
     *
     * @return int|null
     */
    public function getMaxContextLimitExtended(): ?int
    {
        return $this->max_context_limit_extended;
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
     * tool_calls or internal
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

    public function getTurnTrigger(): string
    {
        return $this->turn_trigger ?? 'none';
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

    /**
         * The preset whose system prompt drives compaction summarisation.
         * Null means compaction is off for this preset — CompactionService
         * treats a null (or missing) compressor as "feature disabled".
         */
    public function compressorPreset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'compressor_preset_id');
    }

    /**
     * ID of the compressor preset, or null when compaction is off.
     * This is the single source of the "compression profile" — the profile is
     * whatever the compressor preset's prompt encodes (task-state / salience).
     */
    public function getCompressorPresetId(): ?int
    {
        return $this->compressor_preset_id;
    }

    /**
     * The preset whose system prompt drives knowledge formulation.
     * Null means knowledge formulation is off for this preset.
     */
    public function knowledgeFormulatorPreset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'knowledge_formulator_preset_id');
    }

    /**
     * The preset whose system prompt drives knowledge formulation.
     * Null means knowledge formulation is off for this preset.
     */
    public function getKnowledgeFormulatorPresetId(): ?int
    {
        return $this->knowledge_formulator_preset_id;
    }

    /**
     * Whether agent-driven / watchdog compaction is available for this preset.
     * True only when a compressor preset is configured.
     */
    public function hasCompaction(): bool
    {
        return !is_null($this->compressor_preset_id);
    }

    /**
     * Active-window message count at which a compaction is force-triggered
     * (watchdog safety net). Null/0 means the watchdog is off — the agent's
     * own [compact] calls still work. Kept separate from
     * max_context_limit_extended by design: that field means "how much to carry
     * in work mode", this means "at what size to fold" — opposite intents.
     */
    public function getCompactionWatchdogLimit(): ?int
    {
        $value = $this->compaction_watchdog_limit;
        return ($value === null || $value <= 0) ? null : $value;
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
     * Scope: exclude ephemeral spawned presets from regular listings.
     * Use in UI queries: AiPreset::withoutSpawns()->orderBy('name')->get()
     */
    public function scopeWithoutSpawns($query)
    {
        return $query->where('is_spawned', false);
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

    /**
     * Whether the pre-pass ("reasoning"/"beneath") is enabled for this preset.
     * When true, generateResponse() runs one extra pass over the full context
     * before the speaking pass, exposing its output via [[reasoning]].
     */
    public function getPrePassEnabled(): bool
    {
        return $this->pre_pass_enabled ?? false;
    }

    /**
     * The user-turn instruction injected at the tail of the context for the
     * pre-pass. This is the only thing that differs between the pre-pass and the
     * speaking pass. Null/empty means the feature is effectively inert even if
     * the flag is on — generateResponse() should guard against that.
     */
    public function getPrePassInstruction(): ?string
    {
        return $this->pre_pass_instruction;
    }

}

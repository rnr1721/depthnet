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
        'system_prompt',
        'preset_code',
        'preset_code_next',
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
        'is_active',
        'is_default',
        'created_by',
    ];

    protected $casts = [
        'engine_config' => 'array',
        'metadata' => 'array',
        'loop_interval' => 'integer',
        'max_context_limit' => 'integer',
        'before_execution_wait' => 'integer',
        'allow_handoff_to' => 'boolean',
        'allow_handoff_from' => 'boolean',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'is_active' => true,
        'is_default' => false,
        'allow_handoff_to' => true,
        'allow_handoff_from' => true,
        'error_behavior' => 'stop',
        'before_execution_wait' => 5,
        'engine_config' => '{}',
        'metadata' => '{}',
        'system_prompt' => '',
        'plugins_disabled' => ''
    ];

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

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getSystemPrompt(): string
    {
        return $this->system_prompt ?? '';
    }

    public function getPluginsDisabled(): string
    {
        return $this->plugins_disabled ?? '';
    }

    public function getEngineName(): string
    {
        return $this->engine_name;
    }

    public function getEngineConfig(): array
    {
        return $this->engine_config ?? [];
    }

    public function getLoopInterval(): int
    {
        return $this->loop_interval;
    }

    public function getMaxContextLimit(): int
    {
        return $this->max_context_limit;
    }

    public function getAgentResultMode(): string
    {
        return $this->agent_result_mode;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isDefault(): bool
    {
        return $this->is_default;
    }

    public function getCreatedBy(): ?int
    {
        return $this->created_by;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->created_at;
    }

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updated_at;
    }

    public function getPresetCode(): ?string
    {
        return $this->preset_code;
    }

    public function getPresetCodeNext(): ?string
    {
        return $this->preset_code_next;
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

}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPreset extends Model
{
    protected $table = "ai_presets";

    protected $fillable = [
        'name',
        'description',
        'engine_name',
        'system_prompt',
        'notes',
        'dopamine_level',
        'plugins_disabled',
        'engine_config',
        'is_active',
        'is_default',
        'created_by',
    ];

    protected $casts = [
        'engine_config' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'dopamine_level' => 'integer'
    ];

    protected $attributes = [
        'is_active' => true,
        'is_default' => false,
        'engine_config' => '{}',
        'system_prompt' => '',
        'notes' => '',
        'dopamine_level' => 5,
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

    // AiPresetInterface implementation
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
        return $this->system_prompt;
    }

    public function getNotes(): string
    {
        return $this->notes ?? '';
    }

    public function getDopamineLevel(): int
    {
        return $this->dopamine_level;
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
}

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
        'notes',
        'dopamine_level',
        'plugins_disabled',
        'engine_config',
        'metadata',
        'is_active',
        'is_default',
        'created_by',
    ];

    protected $casts = [
        'engine_config' => 'array',
        'metadata' => 'array',
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
        'metadata' => '{}',
        'system_prompt' => '',
        //'notes' => '',
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
     * Get metadata value by key or all metadata
     */
    public function getMetadata(?string $key = null, $default = null)
    {
        $metadata = $this->metadata ?? [];

        if ($key === null) {
            return $metadata;
        }

        // Support dot notation for nested keys
        if (strpos($key, '.') !== false) {
            return data_get($metadata, $key, $default);
        }

        return $metadata[$key] ?? $default;
    }

    /**
     * Set metadata value by key
     */
    public function setMetadata(string $key, $value): void
    {
        $metadata = $this->metadata ?? [];

        // Support dot notation for nested keys
        if (strpos($key, '.') !== false) {
            data_set($metadata, $key, $value);
        } else {
            $metadata[$key] = $value;
        }

        $this->metadata = $metadata;
        $this->save();
    }

    /**
     * Update multiple metadata values at once
     */
    public function updateMetadata(array $data): void
    {
        $metadata = $this->metadata ?? [];

        foreach ($data as $key => $value) {
            if (strpos($key, '.') !== false) {
                data_set($metadata, $key, $value);
            } else {
                $metadata[$key] = $value;
            }
        }

        $this->metadata = $metadata;
        $this->save();
    }

    /**
     * Remove metadata key
     */
    public function removeMetadata(string $key): void
    {
        $metadata = $this->metadata ?? [];

        if (strpos($key, '.') !== false) {
            data_forget($metadata, $key);
        } else {
            unset($metadata[$key]);
        }

        $this->metadata = $metadata;
        $this->save();
    }

    /**
     * Check if metadata key exists
     */
    public function hasMetadata(string $key): bool
    {
        $metadata = $this->metadata ?? [];

        if (strpos($key, '.') !== false) {
            return data_get($metadata, $key) !== null;
        }

        return isset($metadata[$key]);
    }

    /**
     * Clear all metadata or specific namespace
     */
    public function clearMetadata(?string $namespace = null): void
    {
        if ($namespace === null) {
            $this->metadata = [];
        } else {
            $metadata = $this->metadata ?? [];
            unset($metadata[$namespace]);
            $this->metadata = $metadata;
        }

        $this->save();
    }

    /**
     * Get metadata for specific plugin/namespace
     */
    public function getPluginMetadata(string $pluginName, ?string $key = null, $default = null)
    {
        $pluginData = $this->getMetadata($pluginName, []);

        if ($key === null) {
            return $pluginData;
        }

        return $pluginData[$key] ?? $default;
    }

    /**
     * Set metadata for specific plugin/namespace
     */
    public function setPluginMetadata(string $pluginName, string $key, $value): void
    {
        $this->setMetadata("{$pluginName}.{$key}", $value);
    }

    /**
     * Update multiple values for specific plugin
     */
    public function updatePluginMetadata(string $pluginName, array $data): void
    {
        $pluginData = $this->getMetadata($pluginName, []);
        $pluginData = array_merge($pluginData, $data);
        $this->setMetadata($pluginName, $pluginData);
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

    /**
     * Get formatted memory content as numbered list
     * This method provides backward compatibility with the old notes-based approach
     */
    public function getFormattedMemory(): string
    {
        $memoryItems = $this->memoryItems;

        if ($memoryItems->isEmpty()) {
            return $this->notes ?? '';
        }

        $formatted = [];
        foreach ($memoryItems as $index => $item) {
            $number = $index + 1;
            $formatted[] = "{$number}. {$item->content}";
        }

        return implode("\n", $formatted);
    }

    /**
     * Get total memory length in characters
     */
    public function getMemoryLength(): int
    {
        if ($this->memoryItems->isEmpty()) {
            return strlen($this->notes ?? '');
        }

        return $this->memoryItems->sum('content_length');
    }

    /**
     * Check if preset has any memory content (either new format or legacy notes)
     */
    public function hasMemory(): bool
    {
        return $this->memoryItems->isNotEmpty() || !empty($this->notes);
    }
}

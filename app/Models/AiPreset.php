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
        'plugin_configs',
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
        'plugin_configs' => 'array',
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
        'plugin_configs' => '{}',
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
     * Get system prompt for this preset
     *
     * @return string
     */
    public function getSystemPrompt(): string
    {
        return $this->system_prompt ?? '';
    }

    public function getPluginsDisabled(): string
    {
        return $this->plugins_disabled ?? '';
    }

    /**
     * Get plugin configurations (JSON approach)
     *
     * @return array
     */
    public function getPluginConfigs(): array
    {
        return $this->plugin_configs ?? [];
    }

    /**
     * Get specific plugin configuration
     *
     * @param string $pluginName
     * @return array|null
     */
    public function getPluginConfig(string $pluginName): ?array
    {
        $configs = $this->getPluginConfigs();
        return $configs[$pluginName] ?? null;
    }

    /**
     * Check if plugin is enabled for this preset
     *
     * @param string $pluginName
     * @return bool
     */
    public function isPluginEnabled(string $pluginName): bool
    {
        // First check new plugin_configs
        $config = $this->getPluginConfig($pluginName);
        if ($config !== null) {
            return $config['enabled'] ?? true;
        }

        // Fallback to old plugins_disabled field
        $disabledPlugins = array_map('trim', explode(',', $this->getPluginsDisabled()));
        return !in_array($pluginName, $disabledPlugins);
    }

    /**
     * Set plugin configuration for this preset
     *
     * @param string $pluginName
     * @param array $config
     * @return bool
     */
    public function setPluginConfig(string $pluginName, array $config): bool
    {
        $configs = $this->getPluginConfigs();
        $configs[$pluginName] = $config;

        return $this->update(['plugin_configs' => $configs]);
    }

    /**
     * Enable plugin for this preset
     *
     * @param string $pluginName
     * @param array $config
     * @return bool
     */
    public function enablePlugin(string $pluginName, array $config = []): bool
    {
        $configs = $this->getPluginConfigs();
        $configs[$pluginName] = array_merge($config, ['enabled' => true]);

        return $this->update(['plugin_configs' => $configs]);
    }

    /**
     * Disable plugin for this preset
     *
     * @param string $pluginName
     * @return bool
     */
    public function disablePlugin(string $pluginName): bool
    {
        $configs = $this->getPluginConfigs();
        if (isset($configs[$pluginName])) {
            $configs[$pluginName]['enabled'] = false;
        } else {
            $configs[$pluginName] = ['enabled' => false];
        }

        return $this->update(['plugin_configs' => $configs]);
    }

    /**
     * Get all enabled plugins for this preset
     *
     * @return array Plugin names that are enabled
     */
    public function getEnabledPlugins(): array
    {
        $enabledPlugins = [];
        $configs = $this->getPluginConfigs();

        foreach ($configs as $pluginName => $config) {
            if ($config['enabled'] ?? true) {
                $enabledPlugins[] = $pluginName;
            }
        }

        return $enabledPlugins;
    }

    /**
     * Copy plugin configurations from another preset
     *
     * @param AiPreset $sourcePreset
     * @return bool
     */
    public function copyPluginConfigsFrom(AiPreset $sourcePreset): bool
    {
        return $this->update([
            'plugin_configs' => $sourcePreset->getPluginConfigs()
        ]);
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
     * Get preset code for next
     *
     * @return string|null
     */
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

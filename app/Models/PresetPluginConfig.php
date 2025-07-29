<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plugin Configuration Model for specific preset
 *
 * Stores plugin configuration per preset instead of globally
 *
 * @property int $id
 * @property int $preset_id
 * @property string $plugin_name
 * @property bool $is_enabled
 * @property array|null $config_data
 * @property array|null $default_config
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class PresetPluginConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'preset_id',
        'plugin_name',
        'is_enabled',
        'config_data',
        'default_config',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'config_data' => 'array',
        'default_config' => 'array',
    ];

    protected $attributes = [
        'is_enabled' => true,
    ];

    /**
     * Get the preset that owns this plugin configuration
     */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class);
    }

    /**
     * Get or create plugin configuration for specific preset
     *
     * @param int $presetId
     * @param string $pluginName
     * @param array $defaultConfig
     * @return self
     */
    public static function findOrCreateForPreset(int $presetId, string $pluginName, array $defaultConfig = []): self
    {
        return static::firstOrCreate(
            [
                'preset_id' => $presetId,
                'plugin_name' => $pluginName
            ],
            [
                'is_enabled' => true,
                'config_data' => $defaultConfig,
                'default_config' => $defaultConfig,
            ]
        );
    }

    /**
     * Update plugin configuration for preset
     *
     * @param array $config
     * @return bool
     */
    public function updateConfig(array $config): bool
    {
        return $this->update(['config_data' => $config]);
    }

    /**
     * Reset plugin configuration to default values
     *
     * @return bool
     */
    public function resetToDefaults(): bool
    {
        return $this->update([
            'config_data' => $this->default_config ?? [],
        ]);
    }

    /**
     * Toggle plugin enabled/disabled state
     *
     * @return bool
     */
    public function toggle(): bool
    {
        return $this->update(['is_enabled' => !$this->is_enabled]);
    }

    /**
     * Enable plugin for this preset
     *
     * @return bool
     */
    public function enable(): bool
    {
        return $this->update(['is_enabled' => true]);
    }

    /**
     * Disable plugin for this preset
     *
     * @return bool
     */
    public function disable(): bool
    {
        return $this->update(['is_enabled' => false]);
    }

    /**
     * Get all plugin configurations for specific preset
     *
     * @param int $presetId
     * @return Collection
     */
    public static function getForPreset(int $presetId): Collection
    {
        return static::where('preset_id', $presetId)->get();
    }

    /**
     * Get enabled plugin configurations for specific preset
     *
     * @param int $presetId
     * @return Collection
     */
    public static function getEnabledForPreset(int $presetId): Collection
    {
        return static::where('preset_id', $presetId)
                    ->where('is_enabled', true)
                    ->get();
    }

    /**
     * Copy plugin configurations from one preset to another
     *
     * @param int $fromPresetId
     * @param int $toPresetId
     * @return int Number of configurations copied
     */
    public static function copyBetweenPresets(int $fromPresetId, int $toPresetId): int
    {
        $sourceConfigs = static::where('preset_id', $fromPresetId)->get();
        $copiedCount = 0;

        foreach ($sourceConfigs as $config) {
            static::updateOrCreate(
                [
                    'preset_id' => $toPresetId,
                    'plugin_name' => $config->plugin_name
                ],
                [
                    'is_enabled' => $config->is_enabled,
                    'config_data' => $config->config_data,
                    'default_config' => $config->default_config,
                ]
            );
            $copiedCount++;
        }

        return $copiedCount;
    }

    /**
     * Get plugin statistics for preset
     *
     * @param int $presetId
     * @return array
     */
    public static function getPresetStatistics(int $presetId): array
    {
        $total = static::where('preset_id', $presetId)->count();
        $enabled = static::where('preset_id', $presetId)->where('is_enabled', true)->count();

        return [
            'total_plugins' => $total,
            'enabled_plugins' => $enabled,
            'disabled_plugins' => $total - $enabled,
        ];
    }

    /**
     * Scope for enabled plugins only
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeEnabled($query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope for disabled plugins
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDisabled($query): Builder
    {
        return $query->where('is_enabled', false);
    }
}

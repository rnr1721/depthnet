<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Universal key-value storage for plugin data, scoped to a preset.
 *
 * Used by plugins that need to store lists or named entries beyond
 * what fits in the flat preset_plugin_configs JSON.
 *
 * Examples:
 *   BlockPlugin  — named prompt blocks (key=code, value=text)
 *   Future use   — any ordered/named list a plugin needs to persist
 *
 * @property int    $id
 * @property int    $preset_id
 * @property string $plugin_code   Maps to CommandPluginInterface::getName()
 * @property string $key           Plugin-defined identifier (e.g. block code)
 * @property string|null $value    Plugin-defined content
 * @property int    $position      For ordered lists; 0 = unordered
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class PresetPluginData extends Model
{
    protected $table = 'preset_plugin_data';

    protected $fillable = [
        'preset_id',
        'plugin_code',
        'key',
        'value',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope to a specific preset + plugin.
     */
    public function scopeForPlugin($query, int $presetId, string $pluginCode)
    {
        return $query->where('preset_id', $presetId)
                     ->where('plugin_code', $pluginCode);
    }

    /**
     * Scope ordered by position then key.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('key');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BehaviorPattern — one competing pattern in a preset's ABS population.
 *
 * CRUD / authoring goes through Eloquent (this model). Per-cycle hot writes
 * (fitness deltas, activation bookkeeping, decay) go through
 * BehaviorRuntimeService via the query builder — same split as
 * Contract model vs ContractRuntimeService.
 */
class BehaviorPattern extends Model
{
    protected $table = 'behavior_patterns';

    protected $fillable = [
        'preset_id', 'name', 'trigger', 'intent', 'behavior', 'constraints',
        'lever', 'provenance', 'priority', 'fitness', 'confidence', 'plasticity',
        'immune', 'forced_activation_interval', 'status',
        'activation_count', 'last_activation_seq',
    ];

    protected $casts = [
        'trigger'                    => 'array',
        'behavior'                   => 'array',
        'constraints'                => 'array',
        'lever'                      => 'array',
        'priority'                   => 'float',
        'fitness'                    => 'float',
        'confidence'                 => 'float',
        'plasticity'                 => 'float',
        'immune'                     => 'boolean',
        'forced_activation_interval' => 'integer',
        'activation_count'           => 'integer',
        'last_activation_seq'        => 'integer',
    ];

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    public function activations(): HasMany
    {
        return $this->hasMany(PatternActivation::class, 'pattern_id');
    }

    public function scopeForPreset($query, int $presetId)
    {
        return $query->where('preset_id', $presetId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Whether this pattern's definition is locked in place. Mirrors the contract
     * vital interlock: an immune, non-hypothesis pattern cannot be edited or
     * hard-deleted — it must be revoked to hypothesis first. Immunity protects
     * the definition, NOT the fitness (decay still applies; the quota keeps it
     * activating).
     */
    public function isDefinitionLocked(): bool
    {
        return $this->immune && $this->status !== 'hypothesis';
    }
}

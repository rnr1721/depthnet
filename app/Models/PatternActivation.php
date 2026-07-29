<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PatternActivation — one eligibility-trace row: a pattern activated in a given
 * completed cycle. Read by credit assignment; appended by BehaviorRuntimeService.
 *
 * Mostly written/read via the query builder on the hot path; this model exists
 * for relations, the occasional Eloquent read, and test ergonomics.
 */
class PatternActivation extends Model
{
    protected $table = 'pattern_activations';

    protected $fillable = [
        'preset_id', 'pattern_id', 'cycle_seq', 'reason',
        'credited', 'credit_applied', 'pulse_label',
    ];

    protected $casts = [
        'cycle_seq'      => 'integer',
        'credited'       => 'boolean',
        'credit_applied' => 'float',
    ];

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(BehaviorPattern::class, 'pattern_id');
    }

    public function scopeForPreset($query, int $presetId)
    {
        return $query->where('preset_id', $presetId);
    }
}

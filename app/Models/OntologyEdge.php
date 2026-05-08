<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OntologyEdge
 *
 * A directed, typed, temporal relationship between two nodes.
 *
 * relation_type is a free-form snake_case verb phrase (not an enum):
 *   lives_in, has_surname, defines, weakens, strengthens,
 *   part_of, causes, contradicts, related_to, influences, etc.
 *
 * weight represents relationship strength and can be incremented
 * when the same relationship is confirmed by new evidence.
 *
 * valid_until = null means "currently valid".
 * History is preserved by insert-only — never UPDATE existing edges.
 *
 * @property int         $id
 * @property int         $preset_id
 * @property int         $source_id
 * @property int         $target_id
 * @property string      $relation_type
 * @property float       $weight
 * @property \Carbon\Carbon $valid_from
 * @property \Carbon\Carbon|null $valid_until
 * @property \Carbon\Carbon $created_at
 */
class OntologyEdge extends Model
{
    protected $table = 'ontology_edges';

    public $timestamps = false;

    protected $fillable = [
        'preset_id',
        'source_id',
        'target_id',
        'relation_type',
        'weight',
        'valid_from',
        'valid_until',
        'created_at',
    ];

    protected $casts = [
        'weight'      => 'float',
        'valid_from'  => 'datetime',
        'valid_until' => 'datetime',
        'created_at'  => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(OntologyNode::class, 'source_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(OntologyNode::class, 'target_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForPreset($query, int $presetId)
    {
        return $query->where('preset_id', $presetId);
    }

    public function scopeCurrent($query)
    {
        return $query->whereNull('valid_until');
    }

    public function scopeAtTime($query, \Carbon\Carbon $time)
    {
        return $query
            ->where('valid_from', '<=', $time)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $time));
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('relation_type', $type);
    }
}

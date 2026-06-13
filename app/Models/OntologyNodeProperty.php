<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OntologyNodeProperty
 *
 * A temporal key-value property attached to a node.
 * The value can be either a scalar string or a reference to another node,
 * enabling object-valued properties (e.g. Eugeny.city → Kharkiv node).
 *
 * valid_until = null means "currently valid".
 * Closing a property means setting valid_until = now(), then inserting a new row.
 * This preserves full history without UPDATE.
 *
 * @property int         $id
 * @property int         $node_id
 * @property string      $key            snake_case property name
 * @property string|null $value_scalar   Plain text value (mutually exclusive with value_node_id)
 * @property int|null    $value_node_id  FK to another OntologyNode
 * @property \Carbon\Carbon $valid_from
 * @property \Carbon\Carbon|null $valid_until
 */
class OntologyNodeProperty extends Model
{
    protected $table = 'ontology_node_properties';

    public $timestamps = false;

    protected $fillable = [
        'node_id',
        'key',
        'value_scalar',
        'value_node_id',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        'valid_from'  => 'datetime',
        'valid_until' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function node(): BelongsTo
    {
        return $this->belongsTo(OntologyNode::class, 'node_id');
    }

    public function valueNode(): BelongsTo
    {
        return $this->belongsTo(OntologyNode::class, 'value_node_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

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
}

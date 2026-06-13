<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OntologyNode
 *
 * A named entity in the agent's world model.
 * Nodes are universal — they can represent people, places, concepts,
 * emotions, events, principles, or any other class of thing.
 *
 * The class field is a free-form string (not an enum) so the schema
 * can grow without migrations. Recommended classes:
 *   Person, Place, Concept, Emotion, Event, Object, Principle, Value, Goal
 *
 * Aliases hold alternative names for the same entity (e.g. "Женя",
 * "Евгений", "Eugeny") to avoid duplicate nodes during lookup.
 *
 * Weight increases each time the node is referenced — a rough measure
 * of how central this entity is to the agent's world model.
 *
 * @property int         $id
 * @property int         $preset_id
 * @property string      $canonical_name  Lowercase, snake_case, English noun
 * @property string      $class           Free-form type string
 * @property array|null  $aliases         Alternative names / spellings
 * @property float       $weight          Centrality weight, default 1.0
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class OntologyNode extends Model
{
    protected $table = 'ontology_nodes';

    protected $fillable = [
        'preset_id',
        'canonical_name',
        'class',
        'aliases',
        'weight',
    ];

    protected $casts = [
        'aliases' => 'array',
        'weight'  => 'float',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(OntologyNodeProperty::class, 'node_id');
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(OntologyEdge::class, 'source_id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(OntologyEdge::class, 'target_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForPreset($query, int $presetId)
    {
        return $query->where('preset_id', $presetId);
    }

    public function scopeOfClass($query, string $class)
    {
        return $query->where('class', $class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether the given name matches canonical_name or any alias.
     * Case-insensitive.
     */
    public function matchesName(string $name): bool
    {
        if (mb_strtolower($this->canonical_name) === mb_strtolower($name)) {
            return true;
        }

        foreach ($this->aliases ?? [] as $alias) {
            if (mb_strtolower($alias) === mb_strtolower($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add an alias if not already present.
     */
    public function addAlias(string $alias): void
    {
        $aliases = $this->aliases ?? [];

        if (!in_array($alias, $aliases, true)) {
            $aliases[] = $alias;
            $this->update(['aliases' => $aliases]);
        }
    }

    /**
     * Increment centrality weight by a given delta.
     */
    public function incrementWeight(float $delta = 1.0): void
    {
        $this->increment('weight', $delta);
    }
}

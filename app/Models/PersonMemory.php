<?php

namespace App\Models;

use App\Contracts\Agent\Plugins\TfIdfDocumentInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PersonMemory — a single fact about a person.
 *
 * person_name stores the full display string including aliases:
 *   "Женя / Жэка / James Kvakiani"
 *
 * The first segment before " / " is treated as the primary name.
 * All segments are searched when looking up a person by mention.
 *
 * Semantic search (embedding + TF-IDF fallback) operates on `content`.
 *
 * @property int         $id
 * @property int         $preset_id
 * @property string      $person_name   "Primary / Alias1 / Alias2"
 * @property string      $content       The fact text
 * @property int         $position      Display order within person
 * @property array|null  $metadata
 * @property array|null  $tfidf_vector
 * @property array|null  $embedding
 * @property int|null    $embedding_dim
 */
class PersonMemory extends Model implements TfIdfDocumentInterface
{
    use HasFactory;

    protected $fillable = [
        'preset_id',
        'person_name',
        'content',
        'position',
        'metadata',
        'tfidf_vector',
        'embedding',
        'embedding_dim',
    ];

    protected $casts = [
        'metadata'      => 'array',
        'tfidf_vector'  => 'array',
        'embedding'     => 'array',
        'embedding_dim' => 'integer',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }

    public function scopeForPreset($query, int $presetId)
    {
        return $query->where('preset_id', $presetId);
    }

    /**
     * Find all facts for a person by ID (exact match on person_name prefix or full string).
     * Since person_name can be "Женя / Жэка", we match by the stored string exactly.
     */
    public function scopeForPerson($query, string $personName)
    {
        return $query->whereRaw('LOWER(person_name) = ?', [strtolower($personName)]);
    }

    /**
     * Search across all aliases — matches any segment of "A / B / C".
     * Used by PersonService::findByMention() to resolve unknown names.
     */
    public function scopeMentions($query, string $term)
    {
        $term = strtolower(trim($term));
        return $query->whereRaw('LOWER(person_name) LIKE ?', ["%{$term}%"]);
    }

    // -------------------------------------------------------------------------
    // Alias helpers
    // -------------------------------------------------------------------------

    /**
     * Primary display name — first segment before " / ".
     */
    public function getPrimaryName(): string
    {
        return trim(explode(' / ', $this->person_name)[0]);
    }

    /**
     * All name segments as array.
     */
    public function getAllNames(): array
    {
        return array_map('trim', explode(' / ', $this->person_name));
    }

    // -------------------------------------------------------------------------
    // TfIdfDocumentInterface
    // -------------------------------------------------------------------------

    public function getTfIdfVector(): array
    {
        return $this->tfidf_vector ?? [];
    }

    public function getTextContent(): string
    {
        return $this->content;
    }

    public function getCreatedAt(): ?\Carbon\Carbon
    {
        return $this->created_at;
    }
}

<?php

namespace App\Models;

use App\Contracts\Agent\Plugins\TfIdfDocumentInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FileChunk Model
 *
 * Represents a single text chunk extracted from a File for vector search.
 * Implements TfIdfDocumentInterface so existing TfIdfService and
 * EmbeddingService work with chunks without modification.
 *
 * Mirrors the vector_memories structure but is scoped to a file,
 * not a preset. VectorMemory is intentionally not touched.
 *
 * @property int         $id
 * @property int         $file_id
 * @property int         $chunk_index
 * @property string      $content
 * @property array       $tfidf_vector
 * @property array|null  $embedding
 * @property int|null    $embedding_dim
 * @property array|null  $keywords
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class FileChunk extends Model implements TfIdfDocumentInterface
{
    use HasFactory;

    protected $fillable = [
        'file_id',
        'chunk_index',
        'content',
        'tfidf_vector',
        'embedding',
        'embedding_dim',
        'keywords',
    ];

    protected $casts = [
        'chunk_index'   => 'integer',
        'tfidf_vector'  => 'array',
        'embedding'     => 'array',
        'embedding_dim' => 'integer',
        'keywords'      => 'array',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForFile($query, int $fileId)
    {
        return $query->where('file_id', $fileId);
    }

    public function scopeWithEmbedding($query)
    {
        return $query->whereNotNull('embedding');
    }

    public function scopeWithoutEmbedding($query)
    {
        return $query->whereNull('embedding');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('chunk_index');
    }

    // -------------------------------------------------------------------------
    // TfIdfDocumentInterface
    // -------------------------------------------------------------------------

    /** @inheritDoc */
    public function getTfIdfVector(): array
    {
        return $this->tfidf_vector ?? [];
    }

    /** @inheritDoc */
    public function getTextContent(): string
    {
        return $this->content ?? '';
    }

    /** @inheritDoc */
    public function getCreatedAt(): ?\Carbon\Carbon
    {
        return $this->created_at;
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function getKeywordsCountAttribute(): int
    {
        return count($this->keywords ?? []);
    }

    public function getHasEmbeddingAttribute(): bool
    {
        return !empty($this->embedding);
    }
}

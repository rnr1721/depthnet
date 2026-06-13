<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * File Model
 *
 * Represents a file attached to a preset or available globally.
 * Physical storage is determined by storage_driver:
 *   - laravel  → Laravel storage disk, read-only for the agent
 *   - sandbox  → Preset sandbox home directory, full agent access via TerminalPlugin
 *
 * @property int         $id
 * @property int|null    $preset_id
 * @property string      $original_name
 * @property string      $mime_type
 * @property string      $storage_driver   laravel|sandbox
 * @property string      $storage_path     Relative path within the driver root
 * @property int         $size             File size in bytes
 * @property string      $scope            private|global
 * @property string      $processing_status pending|processing|processed|failed
 * @property array|null  $meta
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class File extends Model
{
    use HasFactory;

    protected $fillable = [
        'preset_id',
        'original_name',
        'mime_type',
        'storage_driver',
        'storage_path',
        'size',
        'scope',
        'processing_status',
        'meta',
    ];

    protected $casts = [
        'preset_id'  => 'integer',
        'size'       => 'integer',
        'meta'       => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(FileChunk::class, 'file_id')->orderBy('chunk_index');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForPreset($query, int $presetId)
    {
        return $query->where(function ($q) use ($presetId) {
            $q->where('preset_id', $presetId)
              ->orWhere('scope', 'global');
        });
    }

    public function scopeGlobal($query)
    {
        return $query->where('scope', 'global');
    }

    public function scopeProcessed($query)
    {
        return $query->where('processing_status', 'processed');
    }

    public function scopePending($query)
    {
        return $query->where('processing_status', 'pending');
    }

    public function scopeInLaravel($query)
    {
        return $query->where('storage_driver', 'laravel');
    }

    public function scopeInSandbox($query)
    {
        return $query->where('storage_driver', 'sandbox');
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * Human-readable file size.
     */
    public function getHumanSizeAttribute(): string
    {
        $bytes = $this->size;

        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1_048_576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1_048_576, 1) . ' MB';
    }

    /**
     * File extension derived from original name.
     */
    public function getExtensionAttribute(): string
    {
        return strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }

    /**
     * Whether the file is accessible by the agent for direct manipulation.
     */
    public function getIsAgentWritableAttribute(): bool
    {
        return $this->storage_driver === 'sandbox';
    }

    /**
     * Whether processing has completed successfully.
     */
    public function getIsProcessedAttribute(): bool
    {
        return $this->processing_status === 'processed';
    }

    /**
     * Total number of chunks produced by the processor.
     */
    public function getChunkCountAttribute(): int
    {
        return $this->chunks()->count();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function markProcessing(): self
    {
        $this->processing_status = 'processing';
        $this->save();
        return $this;
    }

    public function markProcessed(array $meta = []): self
    {
        $this->processing_status = 'processed';
        if ($meta) {
            $this->meta = array_merge($this->meta ?? [], $meta);
        }
        $this->save();
        return $this;
    }

    public function markFailed(string $error): self
    {
        $this->processing_status = 'failed';
        $this->meta = array_merge($this->meta ?? [], ['error' => $error]);
        $this->save();
        return $this;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VectorMemory Model
 *
 * @property int $id
 * @property int $preset_id
 * @property string $content
 * @property array $tfidf_vector
 * @property array $keywords
 * @property float $importance
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class VectorMemory extends Model
{
    use HasFactory;

    protected $fillable = [
        'preset_id',
        'content',
        'tfidf_vector',
        'keywords',
        'importance'
    ];

    protected $casts = [
        'tfidf_vector' => 'array',
        'keywords' => 'array',
        'importance' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Get the preset that owns this vector memory
     */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    /**
     * Scope to filter by preset
     */
    public function scopeForPreset($query, int $presetId)
    {
        return $query->where('preset_id', $presetId);
    }

    /**
     * Scope to get recent memories
     */
    public function scopeRecent($query, int $limit = 10)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }

    /**
     * Scope to get important memories
     */
    public function scopeImportant($query, float $threshold = 1.5)
    {
        return $query->where('importance', '>=', $threshold);
    }

    /**
     * Get vector size (number of features)
     */
    public function getVectorSizeAttribute(): int
    {
        return count($this->tfidf_vector ?? []);
    }

    /**
     * Get keywords count
     */
    public function getKeywordsCountAttribute(): int
    {
        return count($this->keywords ?? []);
    }

    /**
     * Get truncated content for display
     */
    public function getTruncatedContentAttribute(): string
    {
        if (empty($this->content)) {
            return '';
        }

        $content = $this->ensureValidUtf8($this->content);

        $maxLength = 100;

        if (mb_strlen($content, 'UTF-8') <= $maxLength) {
            return $content;
        }

        $truncated = mb_substr($content, 0, $maxLength, 'UTF-8');

        $lastSpace = mb_strrpos($truncated, ' ', 0, 'UTF-8');
        if ($lastSpace !== false && $lastSpace > $maxLength * 0.8) {
            $truncated = mb_substr($truncated, 0, $lastSpace, 'UTF-8');
        }

        return trim($truncated) . '...';
    }

    /**
     * Ensure content is valid UTF-8
     *
     * @param string $text
     * @return string
     */
    protected function ensureValidUtf8(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        if (!mb_check_encoding($text, 'UTF-8')) {
            $detected = mb_detect_encoding($text, ['UTF-8', 'Windows-1251', 'ISO-8859-1'], true);
            if ($detected) {
                $text = mb_convert_encoding($text, 'UTF-8', $detected);
            }
        }

        return $text;
    }

    /**
     * Check if memory contains specific keyword
     */
    public function hasKeyword(string $keyword): bool
    {
        return in_array(strtolower($keyword), array_map('strtolower', $this->keywords ?? []));
    }

    /**
     * Get age in days
     */
    public function getAgeInDaysAttribute(): int
    {
        return $this->created_at->diffInDays(now());
    }

    /**
     * Boost importance of this memory
     */
    public function boost(float $amount = 0.1): self
    {
        $this->importance = min(5.0, $this->importance + $amount);
        $this->save();

        return $this;
    }

    /**
     * Decrease importance of this memory
     */
    public function diminish(float $amount = 0.1): self
    {
        $this->importance = max(0.1, $this->importance - $amount);
        $this->save();

        return $this;
    }
}

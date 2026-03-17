<?php

namespace App\Models;

use App\Contracts\Agent\Plugins\TfIdfDocumentInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillItem extends Model implements TfIdfDocumentInterface
{
    protected $table = 'agent_skill_items';

    protected $fillable = [
        'skill_id',
        'number',
        'content',
        'tfidf_vector',
    ];

    protected $casts = [
        'tfidf_vector' => 'array',
    ];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'skill_id');
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
        return $this->content ?? '';
    }

    public function getCreatedAt(): ?\Carbon\Carbon
    {
        return $this->created_at;
    }
}

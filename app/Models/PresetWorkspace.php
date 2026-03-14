<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PresetWorkspace extends Model
{
    protected $table = 'preset_workspace';

    protected $fillable = [
        'preset_id',
        'key',
        'value',
    ];

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }
}

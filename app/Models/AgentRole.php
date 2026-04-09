<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRole extends Model
{
    use HasFactory;

    protected $table = 'agent_roles';

    protected $fillable = [
        'agent_id',
        'code',
        'preset_id',
        'validator_preset_id',
        'max_attempts',
        'auto_proceed',
    ];

    protected $casts = [
        'agent_id'            => 'integer',
        'preset_id'           => 'integer',
        'validator_preset_id' => 'integer',
        'max_attempts'        => 'integer',
        'auto_proceed'        => 'boolean',
    ];

    protected $attributes = [
        'max_attempts' => 3,
        'auto_proceed' => false,
    ];

    /**
     * Agent this role belongs to.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /**
     * Preset that executes this role.
     */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    /**
     * Optional validator preset for this role.
     */
    public function validatorPreset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'validator_preset_id');
    }

    /**
     * Whether this role has a validator configured.
     */
    public function hasValidator(): bool
    {
        return !is_null($this->validator_preset_id);
    }

    /**
     * Whether to skip planner notification after task done
     * and immediately dispatch next pending task.
     */
    public function isAutoProceed(): bool
    {
        return $this->auto_proceed;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getMaxAttempts(): int
    {
        return $this->max_attempts;
    }
}

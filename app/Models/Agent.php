<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasFactory;

    protected $table = 'agents';

    protected $fillable = [
        'name',
        'description',
        'code',
        'planner_preset_id',
        'is_active',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'planner_preset_id' => 'integer',
        'created_by'        => 'integer',
        'metadata'          => 'array',
    ];

    protected $attributes = [
        'is_active' => true,
        'metadata'  => '{}',
    ];

    /**
     * Planner preset — the brain of the agent.
     * User communicates with this preset directly.
     */
    public function plannerPreset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'planner_preset_id');
    }

    /**
     * Roles assigned to this agent.
     */
    public function roles(): HasMany
    {
        return $this->hasMany(AgentRole::class, 'agent_id');
    }

    /**
     * All tasks belonging to this agent.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(AgentTask::class, 'agent_id');
    }

    /**
     * User who created this agent.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get ID of the agent.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get name of the agent.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get code of the agent.
     */
    public function getCode(): ?string
    {
        return $this->code;
    }

    /**
     * Whether this agent is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Scope for active agents.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Find agent by code.
     */
    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }
}

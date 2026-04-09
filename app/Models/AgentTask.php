<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentTask extends Model
{
    use HasFactory;

    protected $table = 'agent_tasks';

    public const STATUS_PENDING     = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_VALIDATING  = 'validating';
    public const STATUS_DONE        = 'done';
    public const STATUS_FAILED      = 'failed';
    public const STATUS_ESCALATED   = 'escalated';

    protected $fillable = [
        'agent_id',
        'parent_task_id',
        'title',
        'description',
        'assigned_role',
        'status',
        'result',
        'validator_notes',
        'attempts',
        'created_by_role',
        'position',
    ];

    protected $casts = [
        'agent_id'       => 'integer',
        'parent_task_id' => 'integer',
        'attempts'       => 'integer',
        'position'       => 'integer',
    ];

    protected $attributes = [
        'status'   => self::STATUS_PENDING,
        'attempts' => 0,
        'position' => 0,
    ];

    /**
     * Agent this task belongs to.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /**
     * Parent task if this is a subtask.
     */
    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class, 'parent_task_id');
    }

    /**
     * Subtasks of this task.
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(AgentTask::class, 'parent_task_id');
    }

    /**
     * Scope for active (non-terminal) tasks.
     */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            self::STATUS_DONE,
            self::STATUS_FAILED,
            self::STATUS_ESCALATED,
        ]);
    }

    /**
     * Scope ordered by position.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /**
     * Scope for tasks assigned to a specific role.
     */
    public function scopeForRole($query, string $role)
    {
        return $query->where('assigned_role', $role);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAssignedRole(): ?string
    {
        return $this->assigned_role;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_DONE,
            self::STATUS_FAILED,
            self::STATUS_ESCALATED,
        ]);
    }
}

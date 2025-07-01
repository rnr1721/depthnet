<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for tracking sandbox operations
 */
class SandboxOperation extends Model
{
    protected $fillable = [
        'operation_id',
        'type',
        'status',
        'sandbox_id',
        'user_id',
        'metadata',
        'message',
        'logs',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'metadata' => 'array',
        'logs' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Add log entry to operation
     */
    public function addLog(string $message, string $level = 'info'): void
    {
        $logs = $this->logs ?? [];
        $logs[] = [
            'timestamp' => now()->toISOString(),
            'level' => $level,
            'message' => $message
        ];

        $this->logs = $logs;
        $this->message = $message;
        $this->save();
    }

    /**
     * Update operation status
     */
    public function updateStatus(string $status, ?string $message = null): void
    {
        $this->status = $status;

        if ($message) {
            $this->message = $message;
            $this->addLog($message, $status === 'failed' ? 'error' : 'info');
        }

        if ($status === 'processing' && !$this->started_at) {
            $this->started_at = now();
        }

        if (in_array($status, ['completed', 'failed'])) {
            $this->completed_at = now();
        }

        $this->save();
    }

    /**
     * Mark operation as started
     */
    public function markAsStarted(?string $message = null): void
    {
        $this->updateStatus('processing', $message ?? "Operation {$this->type} started");
    }

    /**
     * Mark operation as completed
     */
    public function markAsCompleted(?string $message = null): void
    {
        $this->updateStatus('completed', $message ?? "Operation {$this->type} completed successfully");
    }

    /**
     * Mark operation as failed
     */
    public function markAsFailed(?string $message = null): void
    {
        $this->updateStatus('failed', $message ?? "Operation {$this->type} failed");
    }

    /**
     * Get operation progress percentage
     */
    public function getProgressAttribute(): int
    {
        return match($this->status) {
            'pending' => 0,
            'processing' => 50,
            'completed' => 100,
            'failed' => 100,
            default => 0
        };
    }

    /**
     * Get human readable type
     */
    public function getTypeNameAttribute(): string
    {
        return match($this->type) {
            'create' => 'Creating sandbox',
            'destroy' => 'Destroying sandbox',
            'reset' => 'Resetting sandbox',
            'cleanup' => 'Cleanup all sandboxes',
            default => ucfirst($this->type)
        };
    }
}

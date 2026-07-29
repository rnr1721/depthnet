<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * A single scheduled wake for a preset.
 *
 * See the migration for storage semantics. In short: everything is wall-clock
 * time and seconds; pulses live only at the plugin/placeholder boundary.
 *
 * The model is deliberately thin on behaviour — next_run_at computation lives
 * in WakeScheduleService (which owns the cron library and the pulse
 * conversions), not here, so the model stays a plain data carrier that both the
 * dispatcher and the CRUD layer can share without pulling in services.
 */
class WakeSchedule extends Model
{
    public const TYPE_ONCE     = 'once';
    public const TYPE_INTERVAL = 'interval';
    public const TYPE_DAILY    = 'daily';
    public const TYPE_CRON     = 'cron';

    public const KIND_AGENT = 'agent';
    public const KIND_USER  = 'user';

    /** Message source marker written onto the woken cycle's user message. */
    public const SOURCE_WAKE = 'wake';

    protected $table = 'wake_schedules';

    protected $fillable = [
        'preset_id',
        'enabled',
        'wake_message',
        'schedule_type',
        'run_at',
        'interval_seconds',
        'daily_seconds',
        'cron_expression',
        'next_run_at',
        'last_fired_at',
        'created_by_kind',
    ];

    protected $casts = [
        'enabled'          => 'boolean',
        'run_at'           => 'datetime',
        'next_run_at'      => 'datetime',
        'last_fired_at'    => 'datetime',
        'interval_seconds' => 'integer',
        'daily_seconds'    => 'integer',
        'preset_id'        => 'integer',
    ];

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    /** Rows that are due to fire at or before $moment. */
    public function scopeDue(Builder $query, ?Carbon $moment = null): Builder
    {
        $moment ??= Carbon::now();

        return $query
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $moment);
    }

    public function scopeForPreset(Builder $query, int $presetId): Builder
    {
        return $query->where('preset_id', $presetId);
    }

    public function isRecurring(): bool
    {
        return $this->schedule_type !== self::TYPE_ONCE;
    }

    public function getId(): int
    {
        return $this->id;
    }
}

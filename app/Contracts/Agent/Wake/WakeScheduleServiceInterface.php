<?php

namespace App\Contracts\Agent\Wake;

use App\Models\AiPreset;
use App\Models\WakeSchedule;
use Illuminate\Support\Collection;

/**
 * Single source of truth for a preset's wake schedules.
 *
 * Two front-ends call into this service — WakePlugin (the agent scheduling
 * itself) and the admin CRUD controller (the operator scheduling the agent).
 * Both go through here so next_run_at computation, cron validation, and pulse
 * conversions live in exactly one place.
 *
 * The dispatcher (WakeDispatchCommand) also uses this service to advance a
 * schedule after a successful fire — crucially, recomputeAfterFire() is called
 * AFTER the wake has been enqueued, never before, so a crash can neither drop
 * nor duplicate a wake.
 */
interface WakeScheduleServiceInterface
{
    /**
     * Create a schedule from a raw agent expression (WakePlugin path).
     *
     * Parses "<when> | <message>", validates, computes the initial next_run_at,
     * and persists. Returns the created row, or throws WakeScheduleException with
     * an agent-readable reason on invalid input.
     *
     * @param bool $pulsesEnabled Whether the pulse dialect is accepted.
     */
    public function createFromExpression(
        AiPreset $preset,
        string $expression,
        bool $pulsesEnabled,
        string $createdByKind = WakeSchedule::KIND_AGENT,
    ): WakeSchedule;

    /**
     * Create a schedule from already-structured attributes (admin CRUD path).
     * Validates cron / bounds, computes next_run_at, persists.
     *
     * @param array $attributes schedule_type + the relevant timing fields + wake_message.
     */
    public function createFromAttributes(
        AiPreset $preset,
        array $attributes,
        string $createdByKind = WakeSchedule::KIND_USER,
    ): WakeSchedule;

    /** Update an existing schedule's message and/or timing, recomputing next_run_at. */
    public function update(WakeSchedule $schedule, array $attributes): WakeSchedule;

    /** Cancel (delete) a schedule. */
    public function cancel(WakeSchedule $schedule): void;

    /** All schedules for a preset, ordered by next_run_at ascending (soonest first). */
    public function listForPreset(AiPreset $preset, bool $includeDisabled = true): Collection;

    /** Rows currently due to fire, across all presets. Used by the dispatcher. */
    public function due(): Collection;

    /**
     * Advance a schedule after it has successfully fired.
     *
     * once     → disabled (its single moment has passed).
     * recurring → next_run_at recomputed from the rule; last_fired_at stamped.
     *
     * MUST be called only after the wake has been enqueued.
     */
    public function recomputeAfterFire(WakeSchedule $schedule): void;

    /**
     * Render the preset's schedule as the [[wake_schedule]] placeholder block.
     * When $pulsesEnabled, recurring/daily entries are shown in pulse units so
     * the agent reads its calendar in its own subjective time.
     */
    public function renderScheduleBlock(AiPreset $preset, bool $pulsesEnabled): string;
}

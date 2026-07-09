<?php

namespace App\Services\Agent\Wake;

use App\Models\WakeSchedule;

/**
 * Result of parsing an agent-authored wake expression.
 *
 * Mirrors ParsedSearchQuery's role: the parser reports WHAT it understood; it
 * does not touch the database and does not decide policy. WakeScheduleService
 * turns this into a persisted row (computing next_run_at).
 *
 * The wake_message is carried through untouched — the parser only owns the
 * "when" half of the expression.
 *
 * All timing fields are already normalised to the storage units (seconds /
 * wall-clock), so a pulse-authored expression and a clock-authored one produce
 * identical DTOs downstream. Whether pulses were used at all is recorded in
 * $authoredInPulses purely so the service/placeholder can echo back in the
 * agent's own language.
 */
final class ParsedWakeExpression
{
    private function __construct(
        public readonly string $scheduleType,
        public readonly string $message,
        public readonly ?\Carbon\Carbon $runAt = null,
        public readonly ?int $intervalSeconds = null,
        public readonly ?int $dailySeconds = null,
        public readonly ?string $cronExpression = null,
        public readonly bool $authoredInPulses = false,
        public readonly ?string $error = null,
    ) {
    }

    public static function once(\Carbon\Carbon $runAt, string $message, bool $pulses = false): self
    {
        return new self(WakeSchedule::TYPE_ONCE, $message, runAt: $runAt, authoredInPulses: $pulses);
    }

    public static function interval(int $seconds, string $message, bool $pulses = false): self
    {
        return new self(WakeSchedule::TYPE_INTERVAL, $message, intervalSeconds: $seconds, authoredInPulses: $pulses);
    }

    public static function daily(int $secondsFromMidnight, string $message, bool $pulses = false): self
    {
        return new self(WakeSchedule::TYPE_DAILY, $message, dailySeconds: $secondsFromMidnight, authoredInPulses: $pulses);
    }

    public static function cron(string $expr, string $message): self
    {
        return new self(WakeSchedule::TYPE_CRON, $message, cronExpression: $expr);
    }

    public static function invalid(string $error): self
    {
        return new self('', '', error: $error);
    }

    public function isValid(): bool
    {
        return $this->error === null;
    }

    /** Fields ready to be merged into a WakeSchedule::create() payload. */
    public function toAttributes(): array
    {
        return [
            'schedule_type'    => $this->scheduleType,
            'wake_message'     => $this->message,
            'run_at'           => $this->runAt,
            'interval_seconds' => $this->intervalSeconds,
            'daily_seconds'    => $this->dailySeconds,
            'cron_expression'  => $this->cronExpression,
        ];
    }
}

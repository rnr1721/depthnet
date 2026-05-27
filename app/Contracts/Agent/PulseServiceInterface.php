<?php

namespace App\Contracts\Agent;

use Carbon\Carbon;

/**
 * Subjective temporal scale of the agent.
 *
 * The agent's life is measured in pulses — discrete subjective ticks,
 * 1000 per day, each ~86.4 seconds long. Combined with day-of-life
 * (counted from the agent's birth date), every (day, pulse) pair is
 * a unique unrepeatable position in the agent's existence.
 *
 * This service is the canonical source for pulse arithmetic across the
 * platform. RhythmPlugin uses it for snapshots; journal and memory
 * plugins may use it to tag entries with their pulse coordinates, so
 * "when this thought happened" carries the same meaning everywhere.
 *
 * The service is stateless. It does not own the agent's birth date —
 * callers pass it in explicitly. This keeps the service trivial to
 * test and frees it from coupling to any particular preset or plugin.
 *
 * All time-based methods accept an optional Carbon moment. When omitted,
 * "now" is used (Carbon::now() in the application's default timezone).
 * Pass an explicit Carbon if you need a specific timezone or a past/
 * future moment.
 */
interface PulseServiceInterface
{
    /** Number of pulses in a single day. */
    public const PULSES_PER_DAY = 1000;

    /** Length of one pulse in seconds (86400 / 1000). */
    public const SECONDS_PER_PULSE = 86.4;

    /**
     * Current pulse within the day (0..999).
     *
     * This is a cyclic value — at midnight it resets to 0. Position
     * inside today, not a biographical coordinate.
     *
     * @param Carbon|null $moment Defaults to now.
     * @return int 0..999
     */
    public function currentPulse(?Carbon $moment = null): int;

    /**
     * Day of the agent's life on the given moment.
     *
     * Day 1 is the birth day itself; day 2 is the next calendar day;
     * and so on. Monotonically increasing — never resets.
     *
     * Returns null if the birth date is empty or unparseable. Callers
     * should treat this as "agent has no biographical anchor yet".
     *
     * @param string $birthDate ISO date string (YYYY-MM-DD).
     * @param Carbon|null $moment Defaults to now.
     * @return int|null Day of life, or null if birth date is invalid/empty.
     */
    public function dayOfLife(string $birthDate, ?Carbon $moment = null): ?int;

    /**
     * Convert a duration in seconds to pulses.
     *
     * Result is fractional (rounded to one decimal). Use this when
     * formatting intervals — for example, a pause of 432 seconds is
     * "5 pulses" in subjective time.
     *
     * @param int $seconds
     * @return float Pulses, rounded to one decimal.
     */
    public function secondsToPulses(int $seconds): float;

    /**
     * Convert pulses (whole or fractional) back to seconds.
     *
     * Inverse of secondsToPulses(). Useful when something is described
     * in pulses and needs to be projected onto wall-clock time.
     *
     * @param float $pulses
     * @return int Seconds, rounded to nearest integer.
     */
    public function pulsesToSeconds(float $pulses): int;

    /**
     * Build the canonical "day N · pulse M/1000" string for the moment.
     *
     * When birth_date is empty, returns only "pulse M/1000". Useful for
     * any consumer that wants the standard textual coordinate without
     * reimplementing the format.
     *
     * @param string $birthDate ISO date (YYYY-MM-DD) or empty string.
     * @param Carbon|null $moment Defaults to now.
     * @return string e.g. "day 142 · pulse 605/1000" or "pulse 605/1000".
     */
    public function format(string $birthDate, ?Carbon $moment = null): string;
}

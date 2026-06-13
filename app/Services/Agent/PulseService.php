<?php

namespace App\Services\Agent;

use App\Contracts\Agent\PulseServiceInterface;
use Carbon\Carbon;

/**
 * Default PulseServiceInterface implementation.
 *
 * Pure arithmetic — no I/O, no persistence, no logging. Safe to share
 * as a singleton. See PulseServiceInterface for semantics.
 */
class PulseService implements PulseServiceInterface
{
    public function currentPulse(?Carbon $moment = null): int
    {
        $moment ??= Carbon::now();
        return (int) round(($moment->secondsSinceMidnight() / 86400) * self::PULSES_PER_DAY);
    }

    public function dayOfLife(string $birthDate, ?Carbon $moment = null): ?int
    {
        if ($birthDate === '') {
            return null;
        }

        try {
            $birth = Carbon::parse($birthDate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $moment ??= Carbon::now();
        $on = $moment->copy()->startOfDay();

        // diffInDays is non-negative when $on >= $birth; we add 1 so that
        // the birth day itself is day 1 (not day 0). For moments before
        // birth we return null — there's no day-of-life before life.
        if ($on->lt($birth)) {
            return null;
        }

        return (int) $birth->diffInDays($on) + 1;
    }

    public function secondsToPulses(int $seconds): float
    {
        return round($seconds / self::SECONDS_PER_PULSE, 1);
    }

    public function pulsesToSeconds(float $pulses): int
    {
        return (int) round($pulses * self::SECONDS_PER_PULSE);
    }

    public function format(string $birthDate, ?Carbon $moment = null): string
    {
        $moment ??= Carbon::now();
        $pulse  = $this->currentPulse($moment);
        $day    = $this->dayOfLife($birthDate, $moment);

        if ($day === null) {
            return 'pulse ' . $pulse . '/' . self::PULSES_PER_DAY;
        }

        return 'day ' . $day . ' · pulse ' . $pulse . '/' . self::PULSES_PER_DAY;
    }
}

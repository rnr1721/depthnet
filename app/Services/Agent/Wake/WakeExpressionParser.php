<?php

namespace App\Services\Agent\Wake;

use App\Contracts\Agent\PulseServiceInterface;
use Carbon\Carbon;

/**
 * Parses agent-authored wake expressions into a ParsedWakeExpression.
 *
 * Built in the spirit of SearchDateParser: the "when" and the message are
 * separated by "|", parsing is forgiving, and pulses are just an alternate
 * dialect layered over the canonical (seconds / wall-clock) representation —
 * exactly as en/ru keywords layer over a single Carbon in the date parser.
 *
 * Grammar (the part before "|")
 * -----------------------------
 * CLOCK dialect (always available):
 *   14:30                 → daily at 14:30
 *   daily 14:30           → daily at 14:30 (explicit)
 *   2026-07-12 14:00      → once, absolute
 *   +90m / +2h / +30s     → interval, relative duration
 *   every 6h" / every 30m → interval, recurring
 *   cron:                 → raw cron passthrough
 *
 * PULSE dialect (only when the plugin has pulses enabled for this preset):
 *   p850  / pulse 850   → daily at pulse-position 850 (→ 20:24)
 *   +20p  / +20 pulses  → interval of 20 pulses (→ ~1728s)
 *
 * Anything unrecognised → ParsedWakeExpression::invalid() with a short reason
 * the plugin surfaces to the agent so it can correct next cycle.
 *
 * The parser holds no state and needs only PulseService for the pulse↔seconds
 * conversions. $pulsesEnabled is passed per-call, not stored, so one shared
 * instance serves every preset.
 */
class WakeExpressionParser
{
    public function __construct(
        protected PulseServiceInterface $pulse,
    ) {
    }

    /**
     * @param string $raw           The full expression: "<when> | <message>".
     * @param bool   $pulsesEnabled Whether the pulse dialect is accepted for this preset.
     */
    public function parse(string $raw, bool $pulsesEnabled = false): ParsedWakeExpression
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ParsedWakeExpression::invalid('Empty wake expression.');
        }

        // Split "<when> | <message>". Message is optional but strongly encouraged.
        if (str_contains($raw, '|')) {
            [$when, $message] = array_map('trim', explode('|', $raw, 2));
        } else {
            $when    = $raw;
            $message = '';
        }

        if ($when === '') {
            return ParsedWakeExpression::invalid('Missing the "when" part before "|".');
        }

        // cron: passthrough (validated by the service via cron-expression lib)
        if (preg_match('/^cron\s*:\s*(.+)$/i', $when, $m)) {
            return ParsedWakeExpression::cron(trim($m[1]), $message);
        }

        // Relative duration → interval. "+90m", "+2h", "+30s", and pulse "+20p".
        if (str_starts_with($when, '+')) {
            return $this->parseRelative(substr($when, 1), $message, $pulsesEnabled);
        }

        // Recurring interval: "every 6h", "every 30m", "every 20p".
        if (preg_match('/^every\s+(.+)$/i', $when, $m)) {
            $seconds = $this->durationToSeconds(trim($m[1]), $pulsesEnabled);
            if ($seconds === null) {
                return ParsedWakeExpression::invalid("Unrecognised interval: '{$m[1]}'.");
            }
            $pulses = $this->looksLikePulse(trim($m[1]));
            return ParsedWakeExpression::interval($seconds, $message, $pulses && $pulsesEnabled);
        }

        // Pulse daily position: "p850", "pulse 850".
        if ($pulsesEnabled && preg_match('/^p(?:ulse)?\s*(\d{1,3})$/i', $when, $m)) {
            $p = $this->clampPulse((int) $m[1]);
            $seconds = $this->pulse->pulsesToSeconds((float) $p);
            return ParsedWakeExpression::daily($seconds, $message, true);
        }

        // Absolute date-time → once. "2026-07-12 14:00" or "2026-07-12".
        if (preg_match('/^\d{4}-\d{2}-\d{2}(\s+\d{1,2}:\d{2})?$/', $when)) {
            try {
                $runAt = Carbon::parse($when);
                if ($runAt->isPast()) {
                    return ParsedWakeExpression::invalid('That moment is already in the past.');
                }
                return ParsedWakeExpression::once($runAt, $message);
            } catch (\Throwable) {
                return ParsedWakeExpression::invalid("Could not parse date-time: '{$when}'.");
            }
        }

        // Clock daily position: "14:30" or "daily 14:30".
        if (preg_match('/^(?:daily\s+)?(\d{1,2}):(\d{2})$/i', $when, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h > 23 || $min > 59) {
                return ParsedWakeExpression::invalid("Invalid time: '{$when}'.");
            }
            return ParsedWakeExpression::daily($h * 3600 + $min * 60, $message);
        }

        return ParsedWakeExpression::invalid(
            "Unrecognised wake time: '{$when}'. Try '14:30', '+2h', 'every 6h'"
            . ($pulsesEnabled ? ", 'p850', '+20p'" : '')
            . ", or 'cron: 0 9 * * *'."
        );
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function parseRelative(string $dur, string $message, bool $pulsesEnabled): ParsedWakeExpression
    {
        $seconds = $this->durationToSeconds($dur, $pulsesEnabled);
        if ($seconds === null) {
            return ParsedWakeExpression::invalid("Unrecognised duration: '+{$dur}'.");
        }
        if ($seconds <= 0) {
            return ParsedWakeExpression::invalid('Duration must be positive.');
        }
        // Relative "+X" is a one-shot fire X from now.
        return ParsedWakeExpression::once(
            Carbon::now()->addSeconds($seconds),
            $message,
            $this->looksLikePulse($dur) && $pulsesEnabled
        );
    }

    /**
     * Convert a duration token to seconds. Accepts s/m/h and, when enabled, the
     * pulse unit "p". Returns null when unrecognised.
     */
    private function durationToSeconds(string $token, bool $pulsesEnabled): ?int
    {
        $token = trim($token);

        // Pulse duration: "20p", "20 pulses".
        if ($pulsesEnabled && preg_match('/^(\d+)\s*p(?:ulses?)?$/i', $token, $m)) {
            return $this->pulse->pulsesToSeconds((float) $m[1]);
        }

        // Clock duration: "90m", "2h", "30s".
        if (preg_match('/^(\d+)\s*([smh])$/i', $token, $m)) {
            $n = (int) $m[1];
            return match (strtolower($m[2])) {
                's' => $n,
                'm' => $n * 60,
                'h' => $n * 3600,
            };
        }

        return null;
    }

    private function looksLikePulse(string $token): bool
    {
        return (bool) preg_match('/\d+\s*p(?:ulses?)?$/i', trim($token));
    }

    private function clampPulse(int $v): int
    {
        return max(0, min(999, $v));
    }
}

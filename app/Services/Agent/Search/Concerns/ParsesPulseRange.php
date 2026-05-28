<?php

namespace App\Services\Agent\Search\Concerns;

use App\Contracts\Agent\PulseServiceInterface;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Shared parsing and matching for the "pulse:" search filter.
 *
 * Pulse is a circadian coordinate — position within the day measured in
 * subjective units (0..999, where 1000 pulses ≈ one day). The filter lets
 * an agent retrieve records by part-of-day regardless of the calendar date:
 * "my early-morning thoughts" = pulse:0-300, "late-night reflections" =
 * pulse:800-200 (a range that wraps midnight).
 *
 * This trait carries the two reusable pieces:
 *   - extractPulseRange()  — peel a "pulse:N-M" prefix off a query string
 *   - momentMatchesPulseRange() — test whether a moment's pulse falls in range
 *
 * It deliberately mirrors the logic already proven in VectorMemoryService
 * (parsePulseExpression / matchesPulseRange). It is NOT wired into vector
 * memory — that service keeps its own copy to avoid disturbing working code.
 * The trait exists so the journal (a separate class hierarchy with no shared
 * ancestor) can reuse the same semantics without duplication. Future
 * unification of the two is an optional follow-up, not a prerequisite.
 *
 * Range semantics (identical to vector memory):
 *   - "N-M" with N ≤ M → linear range [N..M]
 *   - "N-M" with N > M → CIRCULAR range [N..999] ∪ [0..M] (crosses midnight)
 *   - "N-"             → lower bound only [N..999]
 *   - "-M"             → upper bound only [0..M]
 *   - lone "N"         → rejected (one pulse ≈ 86s, too narrow to be useful)
 */
trait ParsesPulseRange
{
    /**
     * Extract a "pulse:N-M" prefix from the query, if present.
     *
     * The pulse prefix must be followed by a "|" separator OR stand alone:
     *   "pulse:0-300 | morning thoughts"  → [0, 300, "morning thoughts"]
     *   "pulse:800-200"                   → [800, 200, ""]
     *   "morning thoughts"                → [null, null, "morning thoughts"]
     *
     * Only the FIRST recognised pulse prefix is peeled — the journal's date
     * parser handles the rest of the string afterwards. If the expression
     * after "pulse:" is malformed, the prefix is left intact in the query
     * (mirrors the forgiving behaviour of the date parser).
     *
     * @return array{0: ?int, 1: ?int, 2: string}  [pulseFrom, pulseTo, cleanQuery]
     */
    protected function extractPulseRange(string $query): array
    {
        $query = trim($query);

        // "pulse:<expr> | rest"
        if (preg_match('/^pulse\s*:\s*([^|]+)\|(.*)$/iu', $query, $m)) {
            $parsed = $this->parsePulseExpression(trim($m[1]));
            if ($parsed !== null) {
                return [$parsed[0], $parsed[1], trim($m[2])];
            }
            return [null, null, $query];
        }

        // "pulse:<expr>" alone (pure circadian listing, no semantic part)
        if (preg_match('/^pulse\s*:\s*(.+)$/iu', $query, $m)) {
            $parsed = $this->parsePulseExpression(trim($m[1]));
            if ($parsed !== null) {
                return [$parsed[0], $parsed[1], ''];
            }
            return [null, null, $query];
        }

        return [null, null, $query];
    }

    /**
     * Whether a moment's pulse position falls within [pulseFrom..pulseTo].
     *
     * @param  CarbonInterface|null     $moment   The record's anchor moment (recorded_at).
     * @param  int|null                 $pulseFrom
     * @param  int|null                 $pulseTo
     * @param  PulseServiceInterface    $pulse    For computing pulse-of-day.
     */
    protected function momentMatchesPulseRange(
        ?CarbonInterface $moment,
        ?int $pulseFrom,
        ?int $pulseTo,
        PulseServiceInterface $pulse,
    ): bool {
        if ($moment === null) {
            // Defensive: don't drop a record on pulse grounds if it lacks a date.
            return true;
        }

        if ($pulseFrom === null && $pulseTo === null) {
            return true;
        }

        $p = $pulse->currentPulse(
            $moment instanceof Carbon ? $moment : Carbon::instance($moment)
        );

        if ($pulseFrom !== null && $pulseTo === null) {
            return $p >= $pulseFrom;
        }

        if ($pulseFrom === null && $pulseTo !== null) {
            return $p <= $pulseTo;
        }

        // Both bounds set
        if ($pulseFrom <= $pulseTo) {
            return $p >= $pulseFrom && $p <= $pulseTo;
        }

        // Circular: from > to means the range wraps midnight
        return $p >= $pulseFrom || $p <= $pulseTo;
    }

    /**
     * Build a short human-readable label for a pulse coordinate, used in
     * search output rows. Returns null when birthDate-less day-of-life can't
     * be computed AND the caller wants the full form — but here we always
     * produce at least the cyclic pulse, falling back gracefully.
     *
     * @param  CarbonInterface  $moment
     * @param  string           $birthDate  ISO date or '' (empty → no day-of-life)
     * @param  PulseServiceInterface $pulse
     * @return string  e.g. "day 89 pulse 605" or "pulse 605"
     */
    protected function formatPulseCoordinate(
        CarbonInterface $moment,
        string $birthDate,
        PulseServiceInterface $pulse,
    ): string {
        $carbon = $moment instanceof Carbon ? $moment : Carbon::instance($moment);

        $p   = $pulse->currentPulse($carbon);
        $day = $pulse->dayOfLife($birthDate, $carbon);

        return $day === null
            ? "pulse {$p}"
            : "day {$day} pulse {$p}";
    }

    /**
     * Parse a pulse range expression into [from, to].
     * Single numbers are rejected (too narrow). Bounds clamped to [0..999].
     *
     * @return array{0: ?int, 1: ?int}|null
     */
    private function parsePulseExpression(string $expr): ?array
    {
        // "N-M"
        if (preg_match('/^(\d{1,3})\s*-\s*(\d{1,3})$/', $expr, $m)) {
            return [$this->clampPulse((int) $m[1]), $this->clampPulse((int) $m[2])];
        }

        // "-M"
        if (preg_match('/^-\s*(\d{1,3})$/', $expr, $m)) {
            return [null, $this->clampPulse((int) $m[1])];
        }

        // "N-"
        if (preg_match('/^(\d{1,3})\s*-$/', $expr, $m)) {
            return [$this->clampPulse((int) $m[1]), null];
        }

        // Lone number → reject
        return null;
    }

    /**
     * Clamp a pulse value into the canonical [0..999] range.
     */
    private function clampPulse(int $value): int
    {
        return max(0, min(999, $value));
    }
}

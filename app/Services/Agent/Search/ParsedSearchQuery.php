<?php

namespace App\Services\Agent\Search;

use Carbon\Carbon;

/**
 * Result of parsing a search query for date/time expressions.
 *
 * Carries the resolved date range (if any) and the remaining query text
 * with all recognised date prefixes/expressions stripped out. The calling
 * service decides what to do with the combination:
 *
 *   - `$query` non-empty, no time filter   → pure semantic search
 *   - `$query` non-empty, time filter set  → time-bounded semantic search
 *   - `$query` empty, time filter set      → pure temporal listing
 *   - `$query` empty, no time filter       → invalid input (caller decides)
 *
 * The parser does NOT decide search mode — it only reports what it understood.
 * This keeps SearchDateParser free of any service-specific semantics.
 *
 * Both bounds are inclusive when set: $from is start-of-day, $to is end-of-day
 * for date-only inputs. For absolute timestamps (rare), the parser preserves them
 * as-is.
 */
final class ParsedSearchQuery
{
    public function __construct(
        public readonly ?Carbon $from,
        public readonly ?Carbon $to,
        public readonly string $query,
    ) {
    }

    /**
     * True if the parser recognised any date expression — either an explicit
     * range, a single date, or a keyword like "yesterday" / "вчера".
     */
    public function hasTimeFilter(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    /**
     * True if the query carries semantic content (text to match against).
     */
    public function hasSemanticQuery(): bool
    {
        return $this->query !== '';
    }

    /**
     * Convenience: human-readable description of the time filter, or null
     * if there isn't one. Used by services that format result headers.
     */
    public function describeTimeFilter(): ?string
    {
        if (!$this->hasTimeFilter()) {
            return null;
        }

        if ($this->from !== null && $this->to !== null) {
            // Same day → single date
            if ($this->from->isSameDay($this->to)) {
                return $this->from->toDateString();
            }
            return $this->from->toDateString() . ' to ' . $this->to->toDateString();
        }

        if ($this->from !== null) {
            return 'from ' . $this->from->toDateString();
        }

        return 'until ' . $this->to->toDateString();
    }
}

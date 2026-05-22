<?php

namespace App\Services\Agent\VectorMemory;

use Carbon\CarbonInterface;

/**
 * Filter criteria for retrieving vector memories.
 *
 * A readonly value object that describes WHICH records to fetch — not HOW to
 * search through them. Algorithm parameters (search_limit, similarity_threshold,
 * chain_depth, boost_recent, etc.) live in the plugin config and are passed
 * separately as a plain array to search methods.
 *
 * The split matters because:
 *   - "domains" and "time range" affect the database query (WHERE clauses)
 *   - "similarity threshold" affects in-memory ranking after fetch
 * Mixing them in one structure would blur that boundary.
 *
 * Carbon typing
 * -------------
 * Time bounds are typed as CarbonInterface so the DTO accepts both
 * Carbon\Carbon (returned by SearchDateParser, framework-neutral) and
 * Illuminate\Support\Carbon (Laravel's subclass, what Eloquent casts produce).
 * Both implement the same interface — narrowing the type would force
 * conversions at the boundary for no benefit.
 *
 * Designed to evolve: future filter dimensions (tag whitelist, importance
 * floor, excluded ids, etc.) can be added as constructor params with safe
 * defaults — existing callers keep working unchanged.
 */
final class VectorMemoryQuery
{
    /**
     * @param string[]               $domains  Domain whitelist; empty array = all domains.
     * @param CarbonInterface|null   $from     Inclusive lower bound on created_at; null = no lower bound.
     * @param CarbonInterface|null   $to       Inclusive upper bound on created_at; null = no upper bound.
     * @param int|null               $limit    Max rows to return; null = no limit.
     */
    public function __construct(
        public readonly array $domains = [],
        public readonly ?CarbonInterface $from = null,
        public readonly ?CarbonInterface $to = null,
        public readonly ?int $limit = null,
    ) {
    }

    /**
     * Convenience factory for "no filter at all" — equivalent to fetching everything
     * for the preset. Used as a default argument in service methods.
     */
    public static function empty(): self
    {
        return new self();
    }

    public function hasTimeFilter(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    public function hasDomainFilter(): bool
    {
        return !empty($this->domains);
    }

    public function withDomains(array $domains): self
    {
        return new self(
            domains: $domains,
            from:    $this->from,
            to:      $this->to,
            limit:   $this->limit,
        );
    }

    public function withTimeRange(?CarbonInterface $from, ?CarbonInterface $to): self
    {
        return new self(
            domains: $this->domains,
            from:    $from,
            to:      $to,
            limit:   $this->limit,
        );
    }

    public function withLimit(?int $limit): self
    {
        return new self(
            domains: $this->domains,
            from:    $this->from,
            to:      $this->to,
            limit:   $limit,
        );
    }

    /**
     * Human-readable description of the time filter, or null if there isn't one.
     * Used by services for result headers / error messages.
     */
    public function describeTimeFilter(): ?string
    {
        if (!$this->hasTimeFilter()) {
            return null;
        }

        if ($this->from !== null && $this->to !== null) {
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

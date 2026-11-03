<?php

namespace App\Services\Agent\ContextWindow;

/**
 * One episode of history: from an opening user row to the next opening.
 *
 * A cycle is one user turn plus everything the agent did in response; an
 * episode is one or more cycles. Rows are plain objects with ->id and ->role
 * (Message models in production, stubs in tests).
 *
 * Head kinds:
 *   hard    — a real input (user, API, handoff, recap). Pinnable: it is the
 *             task/question the episode is about.
 *   soft    — the first continuation after a cycle that spoke. The boundary is
 *             known, but the row itself is just "Continue" — nothing to pin.
 *   null    — the start was not in the fetched rows (the episode is longer
 *             than what was read). Its beginning is unknown.
 *
 * A detached head is a hard head found outside the fetched rows by a backward
 * scan; $detachedGap is how many cycles lie between it and the first fetched
 * cycle (those are always elided).
 */
final class Episode
{
    /**
     * @param array<int, array<int, object>> $cycles each cycle starts with a user row
     */
    public function __construct(
        public readonly ?object $head,
        public readonly bool $hardHead,
        public readonly array $cycles,
        public readonly bool $headDetached = false,
        public readonly int $detachedGap = 0,
    ) {
    }

    /** Whether the episode's start is known (it has a hard or soft head). */
    public function isComplete(): bool
    {
        return $this->head !== null;
    }

    /** Whether the head is a real input worth pinning when the episode overflows. */
    public function isPinnable(): bool
    {
        return $this->head !== null && $this->hardHead;
    }

    /**
     * Rows of the fetched cycles, oldest first. A detached head is NOT included
     * — it is not contiguous with them.
     *
     * @return object[]
     */
    public function rows(): array
    {
        return $this->cycles === [] ? [] : array_merge(...$this->cycles);
    }

    public function withDetachedHead(object $head, int $gap): self
    {
        return new self($head, true, $this->cycles, true, max(0, $gap));
    }
}

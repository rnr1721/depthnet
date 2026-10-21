<?php

namespace App\Services\Agent\ContextWindow;

/**
 * Result of segmentation: rows before the first user turn (prefix) and the
 * episodes, oldest first.
 *
 * The prefix is legitimate only at the very start of history (the first cycle
 * runs on a start instruction that is not persisted). Anywhere else it is the
 * tail of a cycle whose user turn fell outside the fetched rows — an orphan.
 */
final class SegmentedHistory
{
    /**
     * @param object[]  $prefix
     * @param Episode[] $episodes
     */
    public function __construct(
        public readonly array $prefix,
        public readonly array $episodes,
    ) {
    }

    public function current(): ?Episode
    {
        return $this->episodes === [] ? null : $this->episodes[array_key_last($this->episodes)];
    }

    public function withCurrent(Episode $episode): self
    {
        $episodes = $this->episodes;
        $episodes[array_key_last($episodes)] = $episode;

        return new self($this->prefix, $episodes);
    }
}

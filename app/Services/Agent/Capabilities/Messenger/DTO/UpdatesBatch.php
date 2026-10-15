<?php

namespace App\Services\Agent\Capabilities\Messenger\DTO;

/**
 * Messages that arrived since the previous cursor, plus the next cursor.
 *
 * Persist $cursor only after the messages have been handled.
 */
final class UpdatesBatch
{
    /**
     * @param InboundMessage[]     $messages  Chronological, incoming only.
     * @param array<string, mixed> $cursor    Opaque; pass back next time.
     * @param bool                 $baseline  First run — positions recorded, nothing reported.
     * @param bool                 $truncated Limit hit; call again soon for the rest.
     * @param string[]             $overflow  Chats where older messages of a burst were skipped.
     */
    public function __construct(
        public readonly array $messages,
        public readonly array $cursor,
        public readonly bool $baseline = false,
        public readonly bool $truncated = false,
        public readonly array $overflow = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->messages === [];
    }
}

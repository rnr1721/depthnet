<?php

namespace App\Services\Agent\Capabilities\Messenger\DTO;

use DateTimeImmutable;

/**
 * A chat as it appears in the dialog list.
 */
final class Dialog
{
    public function __construct(
        public readonly Peer $peer,
        public readonly int $unread = 0,
        public readonly bool $pinned = false,
        public readonly ?DateTimeImmutable $lastActivity = null,
        public readonly ?string $lastMessage = null,
    ) {
    }
}

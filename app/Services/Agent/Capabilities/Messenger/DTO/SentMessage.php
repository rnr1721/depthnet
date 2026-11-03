<?php

namespace App\Services\Agent\Capabilities\Messenger\DTO;

use DateTimeImmutable;

final class SentMessage
{
    public function __construct(
        public readonly Peer $chat,
        public readonly string $messageId,
        public readonly ?DateTimeImmutable $date = null,
    ) {
    }
}

<?php

namespace App\Services\Agent\Capabilities\Messenger\DTO;

final class ChatInfo
{
    public function __construct(
        public readonly Peer $peer,
        public readonly ?int $members = null,
        public readonly ?string $about = null,
    ) {
    }
}

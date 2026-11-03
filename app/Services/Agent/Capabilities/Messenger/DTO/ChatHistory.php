<?php

namespace App\Services\Agent\Capabilities\Messenger\DTO;

/**
 * Messages of one chat, oldest first.
 */
final class ChatHistory
{
    /**
     * @param InboundMessage[] $messages
     */
    public function __construct(
        public readonly Peer $chat,
        public readonly array $messages,
    ) {
    }
}

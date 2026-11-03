<?php

namespace App\Services\Agent\Capabilities\Messenger\Inbound;

final class DeliveryReport
{
    public function __construct(
        public readonly int $delivered = 0,
        public readonly bool $toPool = false,
        public readonly bool $wanted = false,   // something in the batch should wake the agent
        public readonly bool $woke = false,     // a cycle was actually started now
        public readonly ?int $messageId = null, // history message created, if any
    ) {
    }
}

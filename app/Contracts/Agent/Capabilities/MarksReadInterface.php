<?php

namespace App\Contracts\Agent\Capabilities;

use App\Services\Agent\Capabilities\Messenger\DTO\MessengerResult;

/**
 * Optional interface: mark a chat as read.
 *
 * Note this changes state the account owner sees on their own devices. Inbound
 * delivery (ReceivesMessagesInterface) never relies on or touches read state.
 */
interface MarksReadInterface
{
    /**
     * @return MessengerResult value: Peer (the chat)
     */
    public function markRead(string $target): MessengerResult;
}

<?php

namespace App\Contracts\Agent\Capabilities;

use App\Services\Agent\Capabilities\Messenger\DTO\MessengerResult;

/**
 * Optional interface: details about a user, group or channel.
 */
interface DescribesChatsInterface
{
    /**
     * @return MessengerResult value: ChatInfo
     */
    public function info(string $target): MessengerResult;
}

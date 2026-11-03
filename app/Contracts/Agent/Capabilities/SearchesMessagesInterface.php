<?php

namespace App\Contracts\Agent\Capabilities;

use App\Services\Agent\Capabilities\Messenger\DTO\MessengerResult;

/**
 * Optional interface: full-text search inside one chat.
 */
interface SearchesMessagesInterface
{
    /**
     * @return MessengerResult value: ChatHistory (matches, oldest first)
     */
    public function search(string $target, string $query): MessengerResult;
}

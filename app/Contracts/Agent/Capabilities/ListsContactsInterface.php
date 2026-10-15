<?php

namespace App\Contracts\Agent\Capabilities;

use App\Services\Agent\Capabilities\Messenger\DTO\MessengerResult;

/**
 * Optional interface: the account's saved address book.
 */
interface ListsContactsInterface
{
    /**
     * @return MessengerResult value: Peer[]
     */
    public function contacts(): MessengerResult;
}

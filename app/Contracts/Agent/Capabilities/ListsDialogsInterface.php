<?php

namespace App\Contracts\Agent\Capabilities;

use App\Services\Agent\Capabilities\Messenger\DTO\MessengerResult;

/**
 * Optional interface: overview of the account's chats.
 */
interface ListsDialogsInterface
{
    public const KIND_USERS    = 'users';
    public const KIND_GROUPS   = 'groups';
    public const KIND_CHANNELS = 'channels';

    /**
     * Most recently active chats first.
     *
     * @param  string|null $kind One of the KIND_* constants, or null for all.
     * @return MessengerResult value: Dialog[]
     */
    public function dialogs(int $limit = 30, ?string $kind = null): MessengerResult;

    /**
     * Chats that have unread messages.
     *
     * @return MessengerResult value: Dialog[]
     */
    public function unread(int $limit = 20): MessengerResult;
}

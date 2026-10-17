<?php

namespace App\Contracts\Agent\Capabilities;

use App\Services\Agent\Capabilities\Messenger\DTO\MessengerResult;

/**
 * A messenger that can send a message as a reply to another one (a quoted,
 * threaded reply). In a busy group this is what makes clear whom the agent
 * is answering.
 *
 * Implementations must still deliver the text when threading is impossible
 * (the original was deleted, the client is too old) — the words matter more
 * than the thread.
 */
interface RepliesToMessagesInterface
{
    /**
     * @return MessengerResult value: SentMessage
     */
    public function reply(string $target, string $messageId, string $text): MessengerResult;
}

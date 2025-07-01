<?php

namespace App\Contracts\Chat;

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
* Service for managing a general chat
*
* This service handles the exchange of messages in a general chat, where:
* - Different users can send messages
* - All messages are visible to all chat participants
* - The AI ​​model receives formatted messages and can "think" (generate internal thoughts)
*/
interface ChatServiceInterface
{
    /**
     * Get all messages (global feed)
     *
     * @param integer $presetId
     * @param integer $limit Limit the number of messages to retrieve
     * @return Collection All messages
     */
    public function getAllMessages(int $presetId, int $limit = 100): Collection;

    /**
     * Get new messages after a specific ID (new messages)
     *
     * @param integer $presetId
     * @param integer $lastId Last message ID to start from
     * @return Collection
     */
    public function getNewMessages(int $presetId, int $lastId = 0): Collection;

    /**
     * Send message from user
     *
     * @param User $user Current user who sent the message
     * @param $presetId
     * @param string $content Message content
     * @return Message Created message
     */
    public function sendUserMessage(User $user, int $presetId, string $content): Message;

    /**
     * Clear all message history
     *
     * @param $presetId
     * @return void
     */
    public function clearHistory(int $presetId): void;

    /**
     * Messages count
     *
     * @param $presetId
     * @return integer Count of messages
     */
    public function getMessagesCount(int $presetId): int;

    /**
     * Messages Total Count
     *
     * @param integer $presetId
     * @return integer
     */
    public function getTotalMessagesCount(): int;

    /**
     * Delete a specific message
     *
     * @param int $messageId
     * @return bool
     */
    public function deleteMessage(int $messageId): bool;

    /**
     * Clear all history (all presets)
     *
     * @return void
     */
    public function clearAllHistory(): void;
}

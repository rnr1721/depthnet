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
     * @param integer|null $limit
     * @return Collection
     */
    public function getNewMessages(int $presetId, int $lastId = 0, ?int $limit = null): Collection;

    /**
     * Get recent messages for preset (last N messages in chronological order)
     *
     * @param integer $presetId
     * @param integer $limit
     * @return Collection
     */
    public function getRecentMessages(int $presetId, int $limit = 50): Collection;

    /**
     * Get latest messages with pagination metadata
     *
     * @param int $presetId
     * @param int $perPage
     * @return array
     */
    public function getLatestMessagesWithPagination(int $presetId, int $perPage = 30): array;

    /**
     * Check if there are more messages beyond the limit
     *
     * @param int $presetId
     * @param int $loadedCount Number of messages already loaded
     * @return bool
     */
    public function hasMoreMessages(int $presetId, int $loadedCount): bool;

    /**
     * Get messages with pagination (enhanced version with proper metadata)
     *
     * @param int $presetId
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public function getMessagesPaginated(int $presetId, int $page = 1, int $perPage = 30): array;

    /**
     * Get messages with pagination (enhanced version)
     * Returns messages array directly in pagination structure
     *
     * @param int $presetId
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public function getMessagesPaginatedEnhanced(int $presetId, int $page = 1, int $perPage = 30): array;

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

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
 * - The AI model receives formatted messages and can "think" (generate internal thoughts)
 */
interface ChatServiceInterface
{
    /**
     * Get all messages (global feed)
     *
     * @param integer $presetId
     * @param integer|null $limit Limit the number of messages to retrieve
     * @return Collection All messages
     */
    public function getAllMessages(int $presetId, ?int $limit = null): Collection;

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
     * Send message from user.
     * If input pool is enabled — adds the message to the pool as a source.
     * If dispatch=true — flushes the entire pool and sends it as a single JSON message.
     *
     * @param User $user Current user who sent the message
     * @param int $presetId
     * @param string $content Message content
     * @param bool $dispatch Whether to flush and dispatch the pool immediately
     * @return Message Created message (or unsaved stub if pool is accumulating)
     */
    public function sendUserMessage(User $user, int $presetId, string $content, bool $dispatch = false): Message;

    /**
     * Add an external input to the preset's pool from a named source (API input).
     *
     * Only works when the preset is in pool mode — throws \RuntimeException otherwise.
     *
     * When dispatch=false the item is added to the pool and the method returns
     * an array with pool metadata (no Message is created yet):
     *   ['dispatched' => false, 'pool_size' => int, 'items' => Collection]
     *
     * When dispatch=true the entire pool is flushed and sent to the model as a
     * single serialised JSON message. Returns the created Message.
     *
     * This is different from sendUserMessage() in two ways:
     *  - The caller supplies an arbitrary source name (sensor, webhook, etc.)
     *  - It never falls back to plain-text mode — pool mode is required.
     *
     * @param User   $user       Authenticated API user (will own the message if dispatched)
     * @param int    $presetId
     * @param string $sourceName Arbitrary label, e.g. "Throne room temperature sensor"
     * @param string $content    Input content from this source
     * @param bool   $dispatch   true = flush pool now; false = accumulate only
     * @return Message|array     Message when dispatched, metadata array otherwise
     * @throws \RuntimeException When the preset is not in pool mode
     */
    public function sendApiInput(
        User $user,
        int $presetId,
        string $sourceName,
        string $content,
        bool $dispatch = false,
    ): Message|array;

    /**
     * Send an external voice input to a preset without a user context.
     *
     * Works in both single and pool input modes:
     * - pool mode:   adds to the input pool and flushes as JSON
     * - single mode: creates a plain user message with source prefix
     *
     * Automatically triggers the agent thinking cycle if not already running.
     * Designed for external integrations (Rhasspy, etc.) that have no
     * authenticated user but need to deliver speech-recognised text to an agent.
     *
     * @param int    $presetId Target preset ID
     * @param string $content  Recognised speech text to deliver
     * @param string $source   Source identifier shown in message metadata (default: 'rhasspy')
     * @return Message         The created user message
     */
    public function sendVoiceInput(
        int $presetId,
        string $content,
        string $source = 'rhasspy',
    ): Message;

    /**
     * Flush the current input pool and send it as a single message.
     * Returns null if pool is disabled or empty.
     *
     * @param User $user
     * @param int $presetId
     * @return Message|null
     */
    public function dispatchPool(User $user, int $presetId): ?Message;

    /**
     * Clear all message history
     *
     * @param int $presetId
     * @return void
     */
    public function clearHistory(int $presetId): void;

    /**
     * Messages count
     *
     * @param int $presetId
     * @return integer Count of messages
     */
    public function getMessagesCount(int $presetId): int;

    /**
     * Messages Total Count
     *
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

    /**
     * Find message by id or null
     *
     * @param integer $messageId
     * @return Message|null
     */
    public function find(int $messageId): ?Message;
}

<?php

namespace App\Services\Chat;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Chat\ChatServiceInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
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
class ChatService implements ChatServiceInterface
{
    public function __construct(
        protected OptionsServiceInterface $optionsService,
        protected AgentActionsInterface $agentActions,
        protected AgentJobServiceInterface $agentJobService,
        protected PresetRegistryInterface $presetRegistry,
        protected PluginRegistryInterface $pluginRegistry,
        protected ChatStatusServiceInterface $chatStatusService,
        protected InputPoolServiceInterface $inputPoolService,
        protected Message $messageModel,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getAllMessages(int $presetId, ?int $limit = null): Collection
    {
        $query = $this->messageModel
            ->forPreset($presetId)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * @inheritDoc
     */
    public function getNewMessages(int $presetId, int $lastId = 0, ?int $limit = null): Collection
    {
        $query = $this->messageModel
            ->forPreset($presetId)
            ->where('id', '>', $lastId)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * @inheritDoc
     */
    public function getRecentMessages(int $presetId, int $limit = 30): Collection
    {
        return $this->messageModel
            ->forPreset($presetId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Get latest messages with pagination metadata
     *
     * @param int $presetId
     * @param int $perPage
     * @return array
     */
    public function getLatestMessagesWithPagination(int $presetId, int $perPage = 30): array
    {
        $messages = $this->getRecentMessages($presetId, $perPage);
        $totalMessages = $this->getMessagesCount($presetId);

        $loadedCount = count($messages);
        $remainingMessages = max(0, $totalMessages - $loadedCount);

        $totalPages = max(1, ceil($totalMessages / $perPage));
        $currentVirtualPage = $totalPages;

        $hasMorePages = $remainingMessages > 0;

        return [
            'messages' => $messages,
            'pagination' => [
                'current_page' => $currentVirtualPage,
                'last_page' => $totalPages,
                'total' => $totalMessages,
                'per_page' => $perPage,
                'has_more_pages' => $hasMorePages,
                'loaded_count' => $loadedCount,
                'remaining_count' => $remainingMessages,
                'from' => max(1, $totalMessages - $loadedCount + 1),
                'to' => $totalMessages
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function hasMoreMessages(int $presetId, int $loadedCount): bool
    {
        $totalCount = $this->messageModel
            ->forPreset($presetId)
            ->count();

        return $totalCount > $loadedCount;
    }

    /**
     * @inheritDoc
     */
    public function getMessagesPaginated(int $presetId, int $page = 1, int $perPage = 30): array
    {
        $paginator = $this->messageModel
            ->forPreset($presetId)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'has_more_pages' => $paginator->hasMorePages(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getMessagesPaginatedEnhanced(int $presetId, int $page = 1, int $perPage = 30): array
    {
        $paginator = $this->messageModel
            ->forPreset($presetId)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'messages' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'has_more_pages' => $paginator->hasMorePages(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function sendUserMessage(User $user, int $presetId, string $content, bool $dispatch = false): Message
    {
        $messageFromUserLabel = $this->optionsService->get('model_message_from_user', 'message_from_user');

        $preset = $this->presetRegistry->getPreset($presetId);
        if ($this->inputPoolService->isEnabled($preset)) {
            $sourceName = "$messageFromUserLabel {$user->name}:";
            $this->inputPoolService->add($presetId, $sourceName, $content);

            if (!$dispatch) {
                // The pool has been accumulated but not sent - returning an unsaved stub
                return $this->messageModel->make([
                    'role'               => 'user',
                    'content'            => $content,
                    'from_user_id'       => $user->id,
                    'preset_id'          => $presetId,
                    'is_visible_to_user' => false,
                ]);
            }

            // dispatch=true — flush the pool and send everything at once
            $formattedContent = $this->inputPoolService->flush($presetId) ?? $content;
        } else {
            // Pool off - the old way
            $formattedContent = "$messageFromUserLabel {$user->name}:\n$content";
        }

        return $this->createMessage($user, $presetId, $formattedContent);
    }

    /**
     * Create a user message, run commands if needed, trigger agent.
     *
     * @param User $user
     * @param int $presetId
     * @param string $content
     * @return Message
     */
    protected function createMessage(User $user, int $presetId, string $content): Message
    {
        $finalContent = null;
        if ($this->optionsService->get('user_can_run_commands', false)) {
            $finalContent = $user->is_admin ? $this->runCommands($content, $presetId) : null;
        }

        $message = $this->messageModel->create([
            'role'               => 'user',
            'content'            => $content . ($finalContent ?? ''),
            'from_user_id'       => $user->id,
            'preset_id'          => $presetId,
            'is_visible_to_user' => true,
        ]);

        if (!$this->chatStatusService->getChatStatus()) {
            $this->agentJobService->start();
        }

        return $message;
    }

    /**
     * Flush current pool and send as a single message (e.g. from a UI "send pool" button)
     *
     * @param User $user
     * @param int $presetId
     * @return Message|null
     */
    public function dispatchPool(User $user, int $presetId): ?Message
    {
        $preset = $this->presetRegistry->getPreset($presetId);
        if (!$this->inputPoolService->isEnabled($preset)) {
            return null;
        }

        $content = $this->inputPoolService->flush($presetId);

        if (!$content) {
            return null;
        }

        return $this->createMessage($user, $presetId, $content);
    }

    /**
     * Run commands from messages
     *
     * @param string $formattedContent
     * @param int $presetId
     * @return string
     */
    protected function runCommands(string $formattedContent, int $presetId)
    {
        $currentPreset = $this->presetRegistry->getPreset($presetId);
        $this->pluginRegistry->setCurrentPreset($currentPreset);
        $actionResult = $this->agentActions->runActions($formattedContent, $currentPreset, true);
        return $actionResult->getResult();
    }

    /**
     * @inheritDoc
     */
    public function clearHistory(int $presetId): void
    {
        $this->messageModel->forPreset($presetId)->delete();
    }

    /**
     * @inheritDoc
     */
    public function getMessagesCount(int $presetId): int
    {
        return $this->messageModel->forPreset($presetId)->count();
    }

    /**
     * @inheritDoc
     */
    public function getTotalMessagesCount(): int
    {
        return $this->messageModel->count();
    }

    /**
     * @inheritDoc
     */
    public function deleteMessage(int $messageId): bool
    {
        $message = $this->messageModel->find($messageId);

        if (!$message) {
            return false;
        }

        return $message->delete();
    }

    /**
     * @inheritDoc
     */
    public function getAllMessagesGlobal(int $limit = 100): Collection
    {
        return $this->messageModel
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function clearAllHistory(): void
    {
        $this->messageModel->truncate();
    }
}

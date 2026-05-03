<?php

namespace App\Services\Chat;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AgentJobServiceFactoryInterface;
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
 * Service for managing a general chat.
 *
 * Each preset has its own independent message history and agent loop.
 * The loop for a preset is started only when that specific preset is active.
 */
class ChatService implements ChatServiceInterface
{
    public function __construct(
        protected OptionsServiceInterface $optionsService,
        protected AgentActionsInterface $agentActions,
        protected AgentJobServiceFactoryInterface $agentJobServiceFactory,
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
     * Get latest messages with pagination metadata.
     */
    public function getLatestMessagesWithPagination(int $presetId, int $perPage = 30): array
    {
        $messages      = $this->getRecentMessages($presetId, $perPage);
        $totalMessages = $this->getMessagesCount($presetId);

        $loadedCount       = count($messages);
        $remainingMessages = max(0, $totalMessages - $loadedCount);
        $totalPages        = max(1, ceil($totalMessages / $perPage));
        $currentVirtualPage = $totalPages;

        return [
            'messages'   => $messages,
            'pagination' => [
                'current_page'   => $currentVirtualPage,
                'last_page'      => $totalPages,
                'total'          => $totalMessages,
                'per_page'       => $perPage,
                'has_more_pages' => $remainingMessages > 0,
                'loaded_count'   => $loadedCount,
                'remaining_count' => $remainingMessages,
                'from'           => max(1, $totalMessages - $loadedCount + 1),
                'to'             => $totalMessages,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function hasMoreMessages(int $presetId, int $loadedCount): bool
    {
        return $this->messageModel->forPreset($presetId)->count() > $loadedCount;
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
            'data'           => $paginator->items(),
            'current_page'   => $paginator->currentPage(),
            'last_page'      => $paginator->lastPage(),
            'total'          => $paginator->total(),
            'per_page'       => $paginator->perPage(),
            'has_more_pages' => $paginator->hasMorePages(),
            'from'           => $paginator->firstItem(),
            'to'             => $paginator->lastItem(),
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
            'messages'   => $paginator->items(),
            'pagination' => [
                'current_page'   => $paginator->currentPage(),
                'last_page'      => $paginator->lastPage(),
                'total'          => $paginator->total(),
                'per_page'       => $paginator->perPage(),
                'has_more_pages' => $paginator->hasMorePages(),
                'from'           => $paginator->firstItem(),
                'to'             => $paginator->lastItem(),
            ],
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
                return $this->messageModel->make([
                    'role'               => 'user',
                    'content'            => $content,
                    'from_user_id'       => $user->id,
                    'preset_id'          => $presetId,
                    'is_visible_to_user' => false,
                ]);
            }

            $formattedContent = $this->inputPoolService->getAllAsJSON($preset) ?? $content;
            $this->inputPoolService->clear($preset->getId(), true);
        } else {
            $formattedContent = "$messageFromUserLabel {$user->name}:\n$content";
        }

        return $this->createMessage($user, $presetId, $formattedContent);
    }

    /**
     * @inheritDoc
     */
    public function sendApiInput(
        User $user,
        int $presetId,
        string $sourceName,
        string $content,
        bool $dispatch = false,
    ): Message|array {
        $preset = $this->presetRegistry->getPreset($presetId);

        if (!$this->inputPoolService->isEnabled($preset)) {
            throw new \RuntimeException(
                "Preset #{$presetId} is not in pool mode. " .
                "Use the regular message endpoint to send plain-text input."
            );
        }

        $this->inputPoolService->add($presetId, $sourceName, $content);

        if ($dispatch) {
            $formattedContent = $this->inputPoolService->getAllAsJSON($preset);
            $this->inputPoolService->clear($preset->getId(), true);

            // flush() returns null only if pool was somehow empty after our add()
            // that should never happen, but guard anyway
            if ($formattedContent === null) {
                throw new \RuntimeException("Pool was empty after flush — this should not happen.");
            }

            return $this->createMessage($user, $presetId, $formattedContent);
        }

        // Not dispatching — return pool metadata so the caller knows what's waiting
        $items = $this->inputPoolService->getItems($presetId);

        return [
            'dispatched' => false,
            'pool_size'  => $items->count(),
            'items'      => $items->map(fn ($item) => [
                'source'     => $item->source_name,
                'created_at' => $item->created_at->toIso8601String(),
            ]),
        ];
    }

    /**
     * @inheritDoc
     */
    public function sendVoiceInput(
        int $presetId,
        string $content,
        string $source = 'rhasspy',
    ): Message {
        $preset = $this->presetRegistry->getPreset($presetId);

        if ($this->inputPoolService->isEnabled($preset)) {
            $this->inputPoolService->add($presetId, $source, $content);
            $formattedContent = $this->inputPoolService->getAllAsJSON($preset);
            $this->inputPoolService->clear($preset->getId(), true);
        } else {
            $formattedContent = "[{$source}]: {$content}";
        }

        $message = $this->messageModel->create([
            'role'               => 'user',
            'content'            => $formattedContent,
            'from_user_id'       => null,
            'preset_id'          => $presetId,
            'is_visible_to_user' => true,
            'metadata'           => ['source' => $source],
        ]);

        $isActive = $this->chatStatusService->getPresetStatus($presetId);
        $this->agentJobServiceFactory->make()->start($presetId, !$isActive);

        return $message;
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

        $isActive = $this->chatStatusService->getPresetStatus($presetId);
        $this->agentJobServiceFactory->make()->start($presetId, !$isActive);

        return $message;
    }

    /**
     * @inheritDoc
     */
    public function dispatchPool(User $user, int $presetId): ?Message
    {
        $preset = $this->presetRegistry->getPreset($presetId);
        if (!$this->inputPoolService->isEnabled($preset)) {
            return null;
        }

        $content = $this->inputPoolService->flush($preset);

        if (!$content) {
            return null;
        }

        return $this->createMessage($user, $presetId, $content);
    }

    /**
     * Run commands from message content.
     *
     * @param string $formattedContent
     * @param integer $presetId
     * @return string
     */
    protected function runCommands(string $formattedContent, int $presetId): string
    {
        $currentPreset = $this->presetRegistry->getPreset($presetId);
        $this->pluginRegistry->applyPreset($currentPreset);
        $actionResult = $this->agentActions->runActions($formattedContent, $currentPreset, null, true);
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

    /**
     * @inheritDoc
     */
    public function find(int $messageId): ?Message
    {
        return $this->messageModel->find($messageId);
    }
}

<?php

namespace App\Services\Chat;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Chat\ChatServiceInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
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
* - The AI ​​model receives formatted messages and can "think" (generate internal thoughts)
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
        protected Message $messageModel,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getAllMessages(int $presetId, int $limit = 100): Collection
    {
        return $this->messageModel
        ->forPreset($presetId)
        ->orderBy('created_at', 'asc')
        ->limit($limit)
        ->get();
    }

    /**
     * @inheritDoc
     */
    public function getNewMessages(int $presetId, int $lastId = 0): Collection
    {
        return $this->messageModel
            ->forPreset($presetId)
            ->where('id', '>', $lastId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function sendUserMessage(User $user, int $presetId, string $content): Message
    {
        $messageFromUserLabel = $this->optionsService->get('model_message_from_user', 'message_from_user');
        $formattedContent = "$messageFromUserLabel {$user->name}:\n$content";

        $finalContent = $user->is_admin ? $this->runCommands($formattedContent, $presetId) : $formattedContent;

        $message = $this->messageModel->create([
            'role' => 'user',
            'content' => $finalContent,
            'from_user_id' => $user->id,
            'preset_id' => $presetId,
            'is_visible_to_user' => true
        ]);

        if (!$this->chatStatusService->getChatStatus()) {
            $this->agentJobService->start();
        }

        return $message;
    }

    /**
     * Run commands from messages
     *
     * @param string $formattedContent
     * @param int $presetId
     * @return void
     */
    protected function runCommands(string $formattedContent, int $presetId)
    {
        $currentPreset = $this->presetRegistry->getPreset($presetId);
        $this->pluginRegistry->setCurrentPreset($currentPreset);
        $finalMessage = $this->agentActions->runActions($formattedContent, true);
        return $finalMessage->getResult();
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

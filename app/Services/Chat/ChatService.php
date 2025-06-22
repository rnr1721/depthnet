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
    public function getAllMessages(int $limit = 100): Collection
    {
        $result = $this->messageModel->orderBy('created_at', 'asc')
        ->limit($limit)
        ->get();

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getNewMessages(int $lastId = 0): Collection
    {
        return $this->messageModel->where('id', '>', $lastId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function sendUserMessage(User $user, string $content): Message
    {
        $messageFromUserLabel = $this->optionsService->get('model_message_from_user', 'message_from_user');
        $formattedContent = "$messageFromUserLabel {$user->name}:\n$content";

        $finalContent = $user->is_admin ? $this->runCommands($formattedContent) : $formattedContent;

        $message = $this->messageModel->create([
            'role' => 'user',
            'content' => $finalContent,
            'from_user_id' => $user->id,
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
     * @return void
     */
    protected function runCommands(string $formattedContent)
    {
        $currentPreset = $this->presetRegistry->getDefaultPreset();
        $this->pluginRegistry->setCurrentPreset($currentPreset);
        $finalMessage = $this->agentActions->runActions($formattedContent, true);
        return $finalMessage->getResult();
    }

    /**
     * @inheritDoc
     */
    public function clearHistory(): void
    {
        $this->messageModel->truncate();
    }

    /**
     * @inheritDoc
     */
    public function getMessagesCount(): int
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

}

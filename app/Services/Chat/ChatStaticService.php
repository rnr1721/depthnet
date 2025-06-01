<?php

namespace App\Services\Chat;

use App\Contracts\Agent\AgentInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\Message;
use App\Models\User;

class ChatStaticService extends ChatService
{
    protected OptionsServiceInterface $optionsService;
    protected Message $messageModel;
    protected AgentInterface $agent;

    public function __construct(
        OptionsServiceInterface $optionsService,
        Message $messageModel,
        AgentInterface $agent
    ) {
        parent::__construct($optionsService, $messageModel);
        $this->agent = $agent;
    }

    /**
     * @inheritDoc
     */
    public function sendUserMessage(User $user, string $content): Message
    {

        $message = $this->messageModel->create([
            'role' => 'user',
            'content' => $content,
            'from_user_id' => $user->id,
            'is_visible_to_user' => true
        ]);

        $this->agent->think();

        return $message;
    }
}

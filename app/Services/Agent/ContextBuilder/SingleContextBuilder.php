<?php

namespace App\Services\Agent\ContextBuilder;

use App\Contracts\Agent\ContextBuilder\ContextBuilderInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\Message;

/**
 * Single context builder - simple message processing without cycles
 */
class SingleContextBuilder implements ContextBuilderInterface
{
    public function __construct(
        protected Message $messageModel,
        protected OptionsServiceInterface $optionsService
    ) {
    }

    /**
     * Build simple context without cycle management
     *
     * @param Message $messageModel
     * @param OptionsServiceInterface $optionsService
     * @return array
     */
    public function build(): array
    {
        $maxContextLimit = $this->optionsService->get('model_max_context_limit', 8);
        $messages = $this->messageModel->where('role', '!=', 'system')
            ->orderBy('created_at', 'desc')
            ->limit($maxContextLimit)
            ->get()
            ->reverse();

        $context = [];
        foreach ($messages as $message) {
            $context[] = [
                'role' => $message->role,
                'content' => $message->content,
                'from_user_id' => $message->from_user_id,
            ];
        }

        return $context;
    }
}

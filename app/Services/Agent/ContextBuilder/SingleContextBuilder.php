<?php

namespace App\Services\Agent\ContextBuilder;

use App\Contracts\Agent\ContextBuilder\ContextBuilderInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\Message;
use App\Services\Agent\ContextBuilder\Traits\ContentCleaningTrait;

/**
 * Single context builder - simple message processing without cycles
 */
class SingleContextBuilder implements ContextBuilderInterface
{
    use ContentCleaningTrait;

    public function __construct(
        protected Message $messageModel,
        protected OptionsServiceInterface $optionsService
    ) {
    }

    /**
     * Build simple context without cycle management
     *
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

        $context = $this->buildCleanContextFromMessages($messages);

        // Ensure conversation ends with user message for AI API compatibility
        if (!empty($context)) {
            $lastMessage = end($context);
            $lastRole = $lastMessage['role'] ?? null;

            $userRoles = $this->optionsService->get('agent_user_interaction_roles', ['user', 'command']);
            if (!in_array($lastRole, $userRoles)) {
                $context[] = [
                    'role' => 'user',
                    'content' => 'Continue.',
                    'from_user_id' => null
                ];
            }
        }

        return $context;
    }
}

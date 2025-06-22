<?php

namespace App\Services\Agent\ContextBuilder;

use App\Contracts\Agent\ContextBuilder\ContextBuilderInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\Message;
use App\Services\Agent\ContextBuilder\Traits\ContentCleaningTrait;

/**
 * Cycle context builder - adds cycle instructions for continuous thinking
 */
class CycleContextBuilder implements ContextBuilderInterface
{
    use ContentCleaningTrait;

    public function __construct(
        protected Message $messageModel,
        protected OptionsServiceInterface $optionsService
    ) {
    }

    /**
     * Build context with cycle management
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

        // If context is empty, start first cycle
        if (empty($context)) {
            return [
                [
                    'role' => 'user',
                    'content' => $this->getCycleStartInstruction(),
                    'from_user_id' => null
                ]
            ];
        }

        // Check if last message is from user
        $lastMessage = end($context);
        $lastRole = $lastMessage['role'] ?? null;

        // Only add continuation if last message is NOT from user
        if ($lastRole !== 'user') {
            $context[] = [
                'role' => 'user',
                'content' => $this->getCycleContinueInstruction(),
                'from_user_id' => null
            ];
        }

        return $context;
    }

    /**
     * Get cycle start instruction
     *
     * @return string
     */
    protected function getCycleStartInstruction(): string
    {
        return $this->optionsService->get(
            'agent_cycle_start_instruction',
            '[Start your first thinking cycle]'
        );
    }

    /**
     * Get cycle continue instruction
     *
     * @return string
     */
    protected function getCycleContinueInstruction(): string
    {
        return $this->optionsService->get(
            'agent_cycle_continue_instruction',
            '[Continue your thinking cycle]'
        );
    }
}

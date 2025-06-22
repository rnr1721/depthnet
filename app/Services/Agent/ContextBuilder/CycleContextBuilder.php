<?php

namespace App\Services\Agent\ContextBuilder;

use App\Contracts\Agent\ContextBuilder\ContextBuilderInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\Message;

/**
 * Cycle context builder - adds cycle instructions for continuous thinking
 */
class CycleContextBuilder implements ContextBuilderInterface
{
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

        $context = [];
        foreach ($messages as $message) {
            $context[] = [
                'role' => $message->role,
                'content' => $message->content,
                'from_user_id' => $message->from_user_id,
            ];
        }

        // Apply cycle logic
        return $this->applyCycleLogic($context, $this->optionsService);
    }

    /**
     * Apply cycle logic to context
     *
     * @param array $context
     * @param OptionsServiceInterface $optionsService
     * @return array
     */
    protected function applyCycleLogic(array $context, OptionsServiceInterface $optionsService): array
    {
        // If context is empty, start first cycle
        if (empty($context)) {
            return [
                [
                    'role' => 'user',
                    'content' => $this->getCycleStartInstruction($optionsService),
                    'from_user_id' => null
                ]
            ];
        }

        $lastMessage = end($context);
        $lastRole = $lastMessage['role'] ?? null;

        // If last message is from agent (thinking/speaking), add continuation
        if ($this->shouldAddContinuation($lastRole, $optionsService)) {
            $context[] = [
                'role' => 'user',
                'content' => $this->getCycleContinueInstruction($optionsService),
                'from_user_id' => null
            ];
        }

        return $context;
    }

    /**
     * Check if we should add cycle continuation
     *
     * @param string|null $lastRole
     * @param OptionsServiceInterface $optionsService
     * @return bool
     */
    protected function shouldAddContinuation(?string $lastRole, OptionsServiceInterface $optionsService): bool
    {
        // Roles that should trigger continuation
        $continuationRoles = $optionsService->get('agent_cycle_continuation_roles', [
            'thinking', 'speaking'
        ]);

        return in_array($lastRole, $continuationRoles);
    }

    /**
     * Get cycle start instruction
     *
     * @param OptionsServiceInterface $optionsService
     * @return string
     */
    protected function getCycleStartInstruction(OptionsServiceInterface $optionsService): string
    {
        return $optionsService->get(
            'agent_cycle_start_instruction',
            '[Start your first thinking cycle]'
        );
    }

    /**
     * Get cycle continue instruction
     *
     * @param OptionsServiceInterface $optionsService
     * @return string
     */
    protected function getCycleContinueInstruction(OptionsServiceInterface $optionsService): string
    {
        return $optionsService->get(
            'agent_cycle_continue_instruction',
            '[Continue your thinking cycle]'
        );
    }

    /**
     * Get roles that indicate user interaction (should not add continuation)
     *
     * @param OptionsServiceInterface $optionsService
     * @return array
     */
    protected function getUserInteractionRoles(OptionsServiceInterface $optionsService): array
    {
        return $optionsService->get('agent_user_interaction_roles', [
            'user', 'command'
        ]);
    }

    /**
     * Check if context contains recent user interaction
     *
     * @param array $context
     * @param OptionsServiceInterface $optionsService
     * @return bool
     */
    protected function hasRecentUserInteraction(array $context, OptionsServiceInterface $optionsService): bool
    {
        if (empty($context)) {
            return false;
        }

        $userRoles = $this->getUserInteractionRoles($optionsService);
        $checkLastN = $optionsService->get('agent_cycle_user_interaction_check_depth', 3);

        // Check last N messages for user interaction
        $recentMessages = array_slice($context, -$checkLastN);

        foreach ($recentMessages as $message) {
            if (in_array($message['role'] ?? '', $userRoles)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enhanced cycle logic with user interaction awareness
     *
     * @param array $context
     * @param OptionsServiceInterface $optionsService
     * @return array
     */
    protected function applyCycleLogicAdvanced(array $context, OptionsServiceInterface $optionsService): array
    {
        // If context is empty, start first cycle
        if (empty($context)) {
            return [
                [
                    'role' => 'user',
                    'content' => $this->getCycleStartInstruction($optionsService),
                    'from_user_id' => null
                ]
            ];
        }

        $lastMessage = end($context);
        $lastRole = $lastMessage['role'] ?? null;

        // Don't add continuation if there was recent user interaction
        if ($this->hasRecentUserInteraction($context, $optionsService)) {
            return $context;
        }

        // Add continuation if last message is from agent
        if ($this->shouldAddContinuation($lastRole, $optionsService)) {
            $context[] = [
                'role' => 'user',
                'content' => $this->getCycleContinueInstruction($optionsService),
                'from_user_id' => null
            ];
        }

        return $context;
    }
}

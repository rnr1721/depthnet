<?php

namespace App\Services\Agent\ContextBuilder;

use App\Contracts\Agent\ContextBuilder\ContextBuilderInterface;
use App\Contracts\Agent\CyclePrompt\CyclePromptEnricherInterface;
use App\Contracts\Agent\Rag\RagContextEnricherInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Agent\Voice\InnerVoiceEnricherInterface;
use App\Contracts\Auth\AuthServiceInterface;
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\ContextBuilder\Traits\ContentCleaningTrait;

/**
 * Cycle context builder - adds cycle instructions for continuous thinking
 *
 * If the preset has rag_preset_id set, retrieved memory fragments are
 * prepended as a shortcode so the main model sees them as background
 * knowledge before it starts its thinking cycle.
 */
class CycleContextBuilder implements ContextBuilderInterface
{
    use ContentCleaningTrait;

    public function __construct(
        protected Message $messageModel,
        protected OptionsServiceInterface $optionsService,
        protected RagContextEnricherInterface $ragEnricher,
        protected InnerVoiceEnricherInterface      $voiceEnricher,
        protected CyclePromptEnricherInterface $cyclePromptEnricher,
        protected InputPoolServiceInterface $inputPoolService,
        protected ShortcodeManagerServiceInterface $shortcodeManager,
        protected AuthServiceInterface $authService,
    ) {
    }

    /**
     * Build context with cycle management
     *
     * @param AiPreset $preset
     * @return array
     */
    public function build(AiPreset $preset): array
    {
        $maxContextLimit = $preset->getMaxContextLimit();
        $messages = $this->messageModel
            ->forPreset($preset->getId())
            ->where('role', '!=', 'system')
            ->orderBy('created_at', 'desc')
            ->limit($maxContextLimit)
            ->get()
            ->reverse();

        $context = $this->buildCleanContextFromMessages($messages);

        $this->stripLeadingCommandMessages($context);

        // RAG enrichment — register as [[rag_context]] placeholder so the
        // preset's system_prompt can place it wherever makes sense.
        // If the preset doesn't use [[rag_context]], nothing happens.
        $ragBlock = $this->ragEnricher->enrich($preset, $context);

        $this->shortcodeManager->registerShortcode(
            'rag_context',
            'RAG: relevant memories retrieved before this thinking cycle',
            fn () => $ragBlock ?? ''
        );

        // Inner voice — advisor/conscience/muse as [[inner_voice]]
        $voiceBlock = $this->voiceEnricher->enrich($preset, $context);
        $this->shortcodeManager->registerShortcode(
            'inner_voice',
            'Inner voice: advice, doubt or intuition injected before each thinking cycle',
            fn () => $voiceBlock ?? ''
        );

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

        // Check if last message is from user — no continuation needed
        $lastMessage = end($context);
        $lastRole = $lastMessage['role'] ?? null;

        // Only add continuation if last message is NOT from user
        if ($lastRole !== 'user') {
            $content = $this->resolveContinueInstruction($preset, $context);
            $context[] = [
                'role' => 'user',
                'content' => $content,
                'from_user_id' => null
            ];

            $this->messageModel->create([
                'role'               => 'user',
                'content'            => $content,
                'from_user_id'       => $this->authService->getCurrentUserId(),
                'preset_id'          => $preset->getId(),
                'is_visible_to_user' => true,
            ]);

        }

        return $context;
    }

    /**
     * Resolve the cycle continuation instruction.
     * Uses the cycle prompt preset if configured, falls back to static text.
     *
     * @param AiPreset $preset
     * @param array    $context
     * @return string
     */
    protected function resolveContinueInstruction(AiPreset $preset, array $context): string
    {
        // First, put self_signal into the pool if there is a cycle prompt
        $dynamic = $this->cyclePromptEnricher->enrich($preset, $context);
        if ($dynamic !== null && $preset->input_mode === 'pool') {
            $this->inputPoolService->add($preset->id, 'self_signal', $dynamic);
        }

        // If pool mode, flush everything that has accumulated (both self_signal and from the user)
        if ($preset->input_mode === 'pool') {
            $flushed = $this->inputPoolService->flush($preset->id);
            if ($flushed !== null) {
                return $flushed;
            }
        }

        // Fallback — dynamic or static
        return $dynamic ?? $this->getCycleContinueInstruction();
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

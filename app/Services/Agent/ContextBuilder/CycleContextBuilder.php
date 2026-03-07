<?php

namespace App\Services\Agent\ContextBuilder;

use App\Contracts\Agent\ContextBuilder\ContextBuilderInterface;
use App\Contracts\Agent\Rag\RagContextEnricherInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Agent\Voice\InnerVoiceEnricherInterface;
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
        protected ShortcodeManagerServiceInterface $shortcodeManager,
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

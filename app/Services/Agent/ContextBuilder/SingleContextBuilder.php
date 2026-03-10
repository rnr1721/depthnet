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
 * Single context builder - simple message processing without cycles
 *
 * If the preset has rag_preset_id set, retrieved memory fragments are
 * prepended as a placeholder so the main model sees them as background
 * knowledge before it starts its thinking cycle.
 */
class SingleContextBuilder implements ContextBuilderInterface
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
     * Build simple context without cycle management
     *
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
            'RAG: relevant memories retrieved before this request',
            fn () => $ragBlock ?? ''
        );

        // Inner voice — advisor/conscience/muse as [[inner_voice]]
        $voiceBlock = $this->voiceEnricher->enrich($preset, $context, 'single');
        if ($voiceBlock->getResponse()) {
            $voiceText = $this->formatForPlaceholder($voiceBlock->getResponse(), $voiceBlock->getVoicePreset());
            $this->shortcodeManager->registerShortcode(
                'inner_voice',
                'Inner voice: advice, doubt or intuition injected before each request',
                fn () => $voiceText
            );
        }

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

    /**
     * Format text for placeholders before injecting into system prompt.
     *
     * @param string|null $text
     * @param AiPreset $preset
     * @return string
     */
    protected function formatForPlaceholder(?string $text, AiPreset $preset): string
    {
        if (empty($text)) {
            return '';
        }

        $cleanText = trim($text);

        $start = '[' . $preset->getName() . "]\r\n";
        $end   = "[END OF " . $preset->getName() . "]\r\n";

        return $start . $cleanText . "\r\n" . $end;
    }

}

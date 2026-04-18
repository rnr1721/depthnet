<?php

namespace App\Services\Agent\ContextBuilder;

use App\Contracts\Agent\ContextBuilder\ContextBuilderInterface;
use App\Contracts\Agent\Enricher\ContextEnricherInterface;
use App\Contracts\Agent\Enricher\EnricherFactoryInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
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
        protected EnricherFactoryInterface $enricherFactory,
        protected InputPoolServiceInterface $inputPoolService,
        protected ShortcodeManagerServiceInterface $shortcodeManager,
        protected AuthServiceInterface $authService,
    ) {
    }

    /**
     * Build context with cycle management
     *
     * @param AiPreset $preset Preset for context
     * @param AiPreset $sourcePreset Preset for RAG, Inner voice etc
     * @return array
     */
    public function build(AiPreset $preset, ?AiPreset $sourcePreset = null, ?int $maxContextLimit = null): array
    {
        if (!$maxContextLimit) {
            $maxContextLimit = $preset->getMaxContextLimit();
        }
        $sourcePreset = $sourcePreset ?? $preset;
        $messages = $this->messageModel
            ->forPreset($preset->getId())
            ->where('role', '!=', 'system')
            ->orderBy('id', 'desc')
            ->limit($maxContextLimit)
            ->get()
            ->reverse();

        $context = $this->buildCleanContextFromMessages($messages);

        $this->stripLeadingCommandMessages($context);

        // RAG enrichment — register as [[rag_context]] placeholder so the
        // preset's system_prompt can place it wherever makes sense.
        // If the preset doesn't use [[rag_context]], nothing happens.
        $ragEnricher = $this->enricherFactory->makeRagEnricher();
        $ragBlock = $ragEnricher->enrich($sourcePreset, $context);

        $this->shortcodeManager->registerShortcodeForPreset(
            $sourcePreset->getId(),
            'rag_context',
            'RAG: relevant memories retrieved before this thinking cycle',
            fn () => $ragBlock->getResponse() ?? ''
        );

        // Person enrichment
        $personEnricher = $this->enricherFactory->makePersonEnricher();
        $personsBlock   = $personEnricher->enrich($sourcePreset, $context);

        $this->shortcodeManager->registerShortcodeForPreset(
            $sourcePreset->getId(),
            'persons_context',
            'Relevant person facts from memory, Heart-aware',
            fn () => $personsBlock->getResponse() ?? ''
        );

        // Known sources — [[known_sources]]
        if ($this->inputPoolService->isEnabled($sourcePreset)) {
            $knownBlock = $this->inputPoolService->getKnownSourcesBlock($preset->getId());
            $this->shortcodeManager->registerShortcodeForPreset(
                $preset->getId(),
                'known_sources',
                'Data from known sources (sensors, projections, signals)',
                fn () => $knownBlock ?? ''
            );
        }

        $contextEnricher = $this->enricherFactory->makeContextEnricher();

        // If context is empty, start first cycle
        if (empty($context)) {
            return [
                [
                    'role' => 'user',
                    'content' => $this->resolveStartInstruction($contextEnricher, $preset),
                    'from_user_id' => null
                ]
            ];
        }

        // Check if last message is from user — no continuation needed
        $lastMessage = $context[array_key_last($context)];
        $lastRole = $lastMessage['role'] ?? null;

        // Only add continuation if last message is NOT from user
        if ($lastRole !== 'user') {
            $messageText = $this->resolveContinueInstruction($contextEnricher, $preset, $context);
            if ($preset->input_mode === 'pool') {
                $content = $this->inputPoolService->getAllAsJSON($preset);
            } else {
                $content = $messageText;
            }
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
     * Resolve start instruction
     *
     * @param ContextEnricherInterface $contextEnricher
     * @param AiPreset $preset
     * @return string
     */
    protected function resolveStartInstruction(ContextEnricherInterface $contextEnricher, AiPreset $preset): string
    {
        $source = $this->getCycleStartInstruction();
        // If pool mode, flush everything that has accumulated (both self_signal and from the user)
        if ($preset->input_mode === 'pool') {
            $voicePreset = $contextEnricher->getVoicePreset($preset, 'cycle');

            if ($voicePreset) {
                $this->inputPoolService->add($preset->getId(), $voicePreset->getName(), $source);
            } else {
                $this->inputPoolService->add($preset->getId(), $preset->getName(), $source);
            }
            $result = $this->inputPoolService->getAllAsJSON($preset);
            if ($result !== null) {
                return $result;
            }
        }
        return $source;
    }

    /**
     * Resolve the cycle continuation instruction.
     * Uses the cycle prompt preset if configured, falls back to static text.
     *
     * @param ContextEnricherInterface $contextEnricher
     * @param AiPreset $preset
     * @param array    $context
     * @return string
     */
    protected function resolveContinueInstruction(ContextEnricherInterface $contextEnricher, AiPreset $preset, array $context): string
    {
        // First, put self_signal into the pool if there is a cycle prompt
        $dynamic = $contextEnricher->enrich($preset, $context, 'cycle');
        $voicePreset = $dynamic->getPreset();
        if ($dynamic->getResponse() !== null && $preset->input_mode === 'pool' && $voicePreset) {
            $this->inputPoolService->add($preset->getId(), $dynamic->getPreset()->getName(), $dynamic->getResponse());
        } else {
            $this->inputPoolService->add($preset->getId(), $preset->getName(), $this->getCycleContinueInstruction());
        }

        // Fallback — dynamic or static
        return $dynamic->getResponse() ?? $this->getCycleContinueInstruction();
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

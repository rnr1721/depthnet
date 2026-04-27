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
 * Cycle context builder - adds cycle instructions for continuous thinking.
 *
 * RAG pipeline:
 *   Iterates over all PresetRagConfigs ordered by sort_order.
 *   Each config runs enrichWithConfig() on the shared RagContextEnricher,
 *   passing $seenIds by reference so results are deduplicated across configs.
 *   All responses are concatenated and registered as [[rag_context]].
 *
 *   Persons enrichment is now a source option inside each RAG config
 *   ('persons' in sources[]) rather than a separate step.
 */
class CycleContextBuilder implements ContextBuilderInterface
{
    use ContentCleaningTrait;

    public function __construct(
        protected Message                          $messageModel,
        protected OptionsServiceInterface          $optionsService,
        protected EnricherFactoryInterface         $enricherFactory,
        protected InputPoolServiceInterface        $inputPoolService,
        protected ShortcodeManagerServiceInterface $shortcodeManager,
        protected AuthServiceInterface             $authService,
    ) {
    }

    /**
     * Build context with cycle management.
     *
     * @param AiPreset      $preset       Preset for context
     * @param AiPreset|null $sourcePreset Preset for RAG, Inner voice etc.
     * @param int|null      $maxContextLimit
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

        // ── Multi-RAG pipeline ────────────────────────────────────────────────
        $ragEnricher = $this->enricherFactory->makeRagEnricher();
        $ragConfigs  = $this->enricherFactory->getOrderedRagConfigs($sourcePreset);

        $seenIds  = [];
        $ragParts = [];

        foreach ($ragConfigs as $config) {
            $ragBlock = $ragEnricher->enrichWithConfig($sourcePreset, $context, $config, $seenIds);

            if ($ragBlock->getResponse() !== null) {
                $ragParts[] = $ragBlock->getResponse();
            }
        }

        $this->shortcodeManager->registerShortcodeForPreset(
            $sourcePreset->getId(),
            'rag_context',
            'RAG: relevant memories retrieved before this thinking cycle',
            fn () => implode("\n\n", $ragParts)
        );

        // ── Known sources — [[known_sources]] ─────────────────────────────────
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
                    'role'         => 'user',
                    'content'      => $this->resolveStartInstruction($contextEnricher, $preset),
                    'from_user_id' => null,
                ]
            ];
        }

        // Check if last message is from user — no continuation needed
        $lastRole = ($context[array_key_last($context)]['role'] ?? null);

        if ($lastRole !== 'user') {
            $messageText = $this->resolveContinueInstruction($contextEnricher, $preset, $context);

            $content = $preset->input_mode === 'pool'
                ? $this->inputPoolService->getAllAsJSON($preset)
                : $messageText;

            $context[] = [
                'role'         => 'user',
                'content'      => $content,
                'from_user_id' => null,
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
     * Resolve start instruction.
     */
    protected function resolveStartInstruction(ContextEnricherInterface $contextEnricher, AiPreset $preset): string
    {
        $source = $this->getCycleStartInstruction();

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
     */
    protected function resolveContinueInstruction(
        ContextEnricherInterface $contextEnricher,
        AiPreset $preset,
        array $context,
    ): string {
        $dynamic     = $contextEnricher->enrich($preset, $context, 'cycle');
        $voicePreset = $dynamic->getPreset();

        if ($dynamic->getResponse() !== null && $preset->input_mode === 'pool' && $voicePreset) {
            $this->inputPoolService->add($preset->getId(), $voicePreset->getName(), $dynamic->getResponse());
        } else {
            $this->inputPoolService->add($preset->getId(), $preset->getName(), $this->getCycleContinueInstruction());
        }

        return $dynamic->getResponse() ?? $this->getCycleContinueInstruction();
    }

    protected function getCycleStartInstruction(): string
    {
        return $this->optionsService->get('agent_cycle_start_instruction', '[Start your first thinking cycle]');
    }

    protected function getCycleContinueInstruction(): string
    {
        return $this->optionsService->get('agent_cycle_continue_instruction', '[Continue your thinking cycle]');
    }
}

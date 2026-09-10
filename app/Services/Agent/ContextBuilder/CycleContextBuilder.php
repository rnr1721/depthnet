<?php

namespace App\Services\Agent\ContextBuilder;

use App\Contracts\Agent\ContextBuilder\ContextBuilderInterface;
use App\Contracts\Agent\ContextModeResolverInterface;
use App\Contracts\Agent\Enricher\EnricherFactoryInterface;
use App\Contracts\Agent\Enricher\Rag\RagPipelineServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Auth\AuthServiceInterface;
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\ContextBuilder\Traits\ContentCleaningTrait;
use App\Services\Agent\Traits\ResolvesSourcePresetTrait;

/**
 * Cycle context builder - adds cycle instructions for continuous thinking.
 *
 * RAG pipeline (unified):
 *   Iterates over all PresetRagConfigs ordered by sort_order.
 *   Each config runs enrichWithConfig() on the shared RagContextEnricher,
 *   which now returns a structured RagDataInterface payload alongside its
 *   text response.
 *   All payloads are merged by RagAggregator (cross-config dedup + ranking),
 *   then rendered as a single block by RagContentFormatter, registered as
 *   [[rag_context]].
 *
 *   Each individual config still emits its own system message (the text
 *   response on the EnricherResponse) — so per-config visibility is preserved.
 *
 * Inner voice pipeline:
 *   Unchanged — iterates over enabled PresetInnerVoiceConfigs ordered by
 *   sort_order. All non-null responses are concatenated and registered as
 *   [[inner_voice]].
 *
 * Cycle prompt (anti-loop):
 *   A single CyclePromptEnricher call using cycle_prompt_preset_id.
 *   Its output goes into the input pool — not into [[inner_voice]].
 *
 * Compaction window:
 *   History is read through ->activeWindow() (compacted = false). Messages that
 *   have been folded into a recap stay in the DB but drop out of the agent's
 *   active window here. RAG is deliberately NOT filtered this way — it reads the
 *   journal/vector substrate (separate tables), so folded detail stays reachable.
 *   The recap row itself is compacted = false (it IS the active summary), so it
 *   enters context normally and becomes the trailing user turn the cycle
 *   continues from.
 */
class CycleContextBuilder implements ContextBuilderInterface
{
    use ContentCleaningTrait;
    use ResolvesSourcePresetTrait;

    public function __construct(
        protected Message                          $messageModel,
        protected OptionsServiceInterface          $optionsService,
        protected EnricherFactoryInterface         $enricherFactory,
        protected InputPoolServiceInterface        $inputPoolService,
        protected ShortcodeManagerServiceInterface $shortcodeManager,
        protected AuthServiceInterface             $authService,
        protected ContextModeResolverInterface     $contextModeResolver,
        protected RagPipelineServiceInterface      $ragPipeline,
        protected ContextInjectionService          $contextInjection,
    ) {
    }

    /**
     * Build context with cycle management.
     *
     * @param AiPreset      $preset          Preset for context
     * @param AiPreset|null $sourcePreset    Preset for RAG, Inner voice etc.
     * @param int|null      $maxContextLimit
     */
    public function build(AiPreset $preset, ?AiPreset $sourcePreset = null, ?int $maxContextLimit = null): array
    {
        if (!$maxContextLimit) {
            $maxContextLimit = $this->contextModeResolver->activeContextLimit($preset);
        }

        $sourcePreset = $sourcePreset ?? $this->resolveSourcePreset($preset);

        $messages = $this->messageModel
            ->forPreset($preset->getId())
            ->activeWindow()
            ->where('role', '!=', 'system')
            ->orderBy('id', 'desc')
            ->limit($maxContextLimit)
            ->get()
            ->reverse();

        $context = $this->buildCleanContextFromMessages($messages);

        $this->stripLeadingCommandMessages($context);

        $this->liftCompactionRecap($context);

        // ── RAG pipeline (unified service) ────────────────────────────────────
        // Resolve the assembly for this cycle — warm cache if available, else a
        // full synchronous assembly. Assembled over the context we just built so
        // RAG queries are formulated over the exact window the model will see.
        $extended = $this->contextModeResolver->isExtended($preset);

        $assembly = $this->ragPipeline->resolveForCycle(
            thinking: $preset,
            source:   $sourcePreset,
            extended: $extended,
            context:  $context,
        );

        $this->ragPipeline->applyToContext($assembly, $preset, $sourcePreset);

        $targetIds = array_unique([$sourcePreset->getId(), $preset->getId()]);

        // ── Multi inner voice pipeline — [[inner_voice]] ──────────────────────
        $voiceEnricher = $this->enricherFactory->makeInnerVoiceEnricher();
        $voiceConfigs  = $this->enricherFactory->getOrderedVoiceConfigs($sourcePreset);
        $voiceParts    = [];

        foreach ($voiceConfigs as $voiceConfig) {
            $block = $voiceEnricher->enrich($sourcePreset, $context, $voiceConfig);

            if ($block !== null) {
                $voiceParts[] = $block;
            }
        }

        if (!empty($voiceParts)) {
            $voiceText = implode("\n\n", $voiceParts);
            foreach ($targetIds as $id) {
                $this->shortcodeManager->registerShortcodeForPreset(
                    $id,
                    'inner_voice',
                    'Inner voice: perspectives injected before this thinking cycle',
                    fn () => $voiceText
                );
            }
        }

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

        // If context is empty, start first cycle.
        //
        // Note: after a compaction, context is NOT empty — the recap row
        // (compacted = false) sits here as the trailing user turn, so this
        // branch is skipped and the cycle continues from the recap rather than
        // from the cold-start instruction. This only fires on a genuinely fresh
        // preset (or one whose entire window was folded AND whose recap has not
        // yet been written — which the compaction handler must avoid by writing
        // the recap before the next context assembly).
        if (empty($context)) {
            return [
                [
                    'role'         => 'user',
                    'content'      => $this->resolveStartInstruction($preset),
                    'from_user_id' => null,
                ]
            ];
        }

        // Check if last message is from user — no continuation needed
        $lastRole = ($context[array_key_last($context)]['role'] ?? null);

        if ($lastRole !== 'user') {
            $messageText = $this->resolveContinueInstruction($preset, $context);

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

        // Inject loaded-skill bodies as the OLDEST messages — the very last step,
        // so RAG / compaction / recap (all already run above) are untouched.
        $context = $this->contextInjection->injectLoadedSkills($context, $preset);

        return $context;
    }

    /**
     * Resolve start instruction for the first cycle.
     */
    protected function resolveStartInstruction(AiPreset $preset): string
    {
        $source = $this->getCycleStartInstruction();

        if ($preset->input_mode === 'pool') {
            $cyclePromptEnricher = $this->enricherFactory->makeCyclePromptEnricher();
            $voicePreset         = $cyclePromptEnricher->getVoicePreset($preset);

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
     * Calls CyclePromptEnricher for anti-loop impulse and adds it to the pool.
     */
    protected function resolveContinueInstruction(AiPreset $preset, array $context): string
    {
        $cyclePromptEnricher = $this->enricherFactory->makeCyclePromptEnricher();
        $dynamic             = $cyclePromptEnricher->enrich($preset, $context);
        $voicePreset         = $cyclePromptEnricher->getVoicePreset($preset);

        if ($dynamic !== null && $preset->input_mode === 'pool' && $voicePreset) {
            $this->inputPoolService->add($preset->getId(), $voicePreset->getName(), $dynamic);
        } else {
            $this->inputPoolService->add($preset->getId(), $preset->getName(), $this->getCycleContinueInstruction());
        }

        return $dynamic ?? $this->getCycleContinueInstruction();
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

<?php

namespace App\Services\Agent\ContextBuilder;

use App\Contracts\Agent\ContextBuilder\ContextBuilderInterface;
use App\Contracts\Agent\Enricher\EnricherFactoryInterface;
use App\Contracts\Agent\Enricher\Rag\RagAggregatorServiceInterface;
use App\Contracts\Agent\Enricher\Rag\RagContentFormatterInterface;
use App\Contracts\Agent\Enricher\Rag\RagDataInterface;
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
        protected RagAggregatorServiceInterface    $ragAggregator,
        protected RagContentFormatterInterface     $ragFormatter,
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
            $maxContextLimit = $preset->getMaxContextLimit();
        }

        $sourcePreset = $sourcePreset ?? $this->resolveSourcePreset($preset);

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

        $seenIds      = [];
        $ragPayloads  = [];

        foreach ($ragConfigs as $config) {
            $ragBlock = $ragEnricher->enrichWithConfig($sourcePreset, $context, $config, $seenIds, $preset);

            $payload = $ragBlock->getResponseData();
            if ($payload instanceof RagDataInterface && !$payload->isEmpty()) {
                $ragPayloads[] = $payload;
            }
        }

        // Aggregate all payloads into a unified result, then format as one block
        $aggregated = $this->ragAggregator->merge($ragPayloads);
        $ragText    = $this->ragFormatter->formatAggregated($aggregated);

        $targetIds = array_unique([$sourcePreset->getId(), $preset->getId()]);

        foreach ($targetIds as $id) {
            $this->shortcodeManager->registerShortcodeForPreset(
                $id,
                'rag_context',
                'RAG: relevant memories retrieved before this thinking cycle',
                fn () => $ragText
            );
        }

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

        // If context is empty, start first cycle
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

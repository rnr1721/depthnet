<?php

namespace App\Services\Agent\ContextBuilder;

use App\Contracts\Agent\ContextBuilder\ContextBuilderInterface;
use App\Contracts\Agent\Enricher\EnricherFactoryInterface;
use App\Contracts\Agent\Enricher\Rag\RagAggregatorServiceInterface;
use App\Contracts\Agent\Enricher\Rag\RagContentFormatterInterface;
use App\Contracts\Agent\Enricher\Rag\RagDataInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\ContextBuilder\Traits\ContentCleaningTrait;
use App\Services\Agent\ContextBuilder\Traits\ContextRagTrait;

/**
 * Single context builder - simple message processing without cycles.
 *
 * RAG pipeline (unified):
 *   Iterates over all PresetRagConfigs ordered by sort_order.
 *   Each config produces a RagDataInterface payload via enrichWithConfig().
 *   All payloads are merged by RagAggregator and rendered as one
 *   [[rag_context]] block by RagContentFormatter.
 *
 *   Individual per-config system messages are still emitted by the
 *   enricher (visible in UI for debugging/observability).
 *
 * Inner voice pipeline:
 *   Unchanged.
 */
class SingleContextBuilder implements ContextBuilderInterface
{
    use ContentCleaningTrait;
    use ContextRagTrait;

    public function __construct(
        protected Message                          $messageModel,
        protected OptionsServiceInterface          $optionsService,
        protected EnricherFactoryInterface         $enricherFactory,
        protected InputPoolServiceInterface        $inputPoolService,
        protected ShortcodeManagerServiceInterface $shortcodeManager,
        protected RagAggregatorServiceInterface    $ragAggregator,
        protected RagContentFormatterInterface     $ragFormatter,
    ) {
    }

    /**
     * Build simple context without cycle management.
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

        $sourcePreset = $sourcePreset ?? $this->resolveSourceRagPreset($preset);

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

        $seenIds     = [];
        $ragPayloads = [];

        foreach ($ragConfigs as $config) {
            $ragBlock = $ragEnricher->enrichWithConfig($sourcePreset, $context, $config, $seenIds, $preset);

            $payload = $ragBlock->getResponseData();
            if ($payload instanceof RagDataInterface && !$payload->isEmpty()) {
                $ragPayloads[] = $payload;
            }
        }

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
                    'Inner voice: perspectives injected before each request',
                    fn () => $voiceText
                );
            }
        }

        // ── Known sources — [[known_sources]] ─────────────────────────────────
        if ($this->inputPoolService->isEnabled($preset)) {
            $knownBlock = $this->inputPoolService->getKnownSourcesBlock($preset->getId());
            $this->shortcodeManager->registerShortcodeForPreset(
                $preset->getId(),
                'known_sources',
                'Data from known sources (sensors, projections, signals)',
                fn () => $knownBlock ?? ''
            );
        }

        // Ensure conversation ends with user message for AI API compatibility
        if (!empty($context)) {
            $lastRole  = end($context)['role'] ?? null;
            $userRoles = $this->optionsService->get('agent_user_interaction_roles', ['user', 'command']);

            if (!in_array($lastRole, $userRoles)) {
                $context[] = [
                    'role'         => 'user',
                    'content'      => 'Continue.',
                    'from_user_id' => null,
                ];
            }
        }

        return $context;
    }
}

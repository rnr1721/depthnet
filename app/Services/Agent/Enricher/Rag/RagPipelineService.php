<?php

namespace App\Services\Agent\Enricher\Rag;

use App\Contracts\Agent\Enricher\EnricherFactoryInterface;
use App\Contracts\Agent\Enricher\Rag\RagAggregatorServiceInterface;
use App\Contracts\Agent\Enricher\Rag\RagContentFormatterInterface;
use App\Contracts\Agent\Enricher\Rag\RagDataInterface;
use App\Contracts\Agent\Enricher\Rag\RagPipelineServiceInterface;
use App\Contracts\Agent\ContextModeResolverInterface;
use App\Contracts\Agent\Enricher\Rag\RagAssemblyCacheInterface;
use App\Contracts\Agent\Enricher\Rag\RagSilentCacheInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\ContextBuilder\Traits\ContentCleaningTrait;

/**
 * RagPipelineService — the single home for RAG assembly and application.
 *
 * Extracted from the duplicated RAG block in CycleContextBuilder and
 * SingleContextBuilder. Splits the pipeline into:
 *
 *   assemble()       — pure retrieval + payload construction (cacheable).
 *                      No shortcode registration. Persists per-config UI
 *                      messages only when $emitSystemMessages (real cycle:
 *                      true; warm-up: false).
 *
 *   applyToContext() — merge + format + register [[rag_context]]. Side effects only.
 *
 * Context handling (hybrid): the real cycle passes the message context it has
 * already assembled (so RAG queries are formulated over the exact window the
 * model sees). Warm-up passes null and this service assembles the last-known
 * active window itself, via the same query the context builders use — so warm
 * and cold see the same history for a given preset.
 */
final class RagPipelineService implements RagPipelineServiceInterface
{
    use ContentCleaningTrait;

    public function __construct(
        private readonly EnricherFactoryInterface         $enricherFactory,
        private readonly RagAggregatorServiceInterface    $ragAggregator,
        private readonly RagContentFormatterInterface     $ragFormatter,
        private readonly ContextModeResolverInterface     $contextModeResolver,
        private readonly ShortcodeManagerServiceInterface $shortcodeManager,
        private readonly RagAssemblyCacheInterface        $warmCache,
        private readonly RagSilentCacheInterface          $silentCache,
        private readonly Message                          $messageModel,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function assemble(
        AiPreset $thinking,
        AiPreset $source,
        bool $extended,
        array $onlyConfigIds = [],
        array &$seenIds = [],
        bool $emitSystemMessages = true,
        ?array $context = null,
    ): RagAssembly {
        $mode = $extended ? 'extended' : 'normal';

        // Hybrid context: use the caller's window (real cycle) or assemble the
        // last-known active window ourselves (warm-up). Same query as the builders.
        $context ??= $this->assembleActiveWindow($thinking, $extended);

        $ragEnricher = $this->enricherFactory->makeRagEnricher();
        $ragConfigs  = $this->enricherFactory->getOrderedRagConfigs($source);

        // Sieve 1: active context mode.
        $ragConfigs = $ragConfigs->filter(fn ($config) => $config->activeInMode($extended));

        // Sieve 2: config-id restriction. Empty = all configs.
        if (!empty($onlyConfigIds)) {
            $idSet      = array_flip(array_map('intval', $onlyConfigIds));
            $ragConfigs = $ragConfigs->filter(fn ($config) => isset($idSet[(int) $config->id]));
        }

        $payloads = [];

        foreach ($ragConfigs as $config) {
            $ragBlock = $ragEnricher->enrichWithConfig($source, $context, $config, $seenIds, $thinking);

            $payload = $ragBlock->getResponseData();
            if ($payload instanceof RagDataInterface && !$payload->isEmpty()) {
                $payloads[] = $payload;
            }

            if ($emitSystemMessages) {
                $this->persistSystemMessage(
                    $ragBlock->getSystemMessage(),
                    $ragBlock->getSystemMessagePresetId(),
                );
            }
        }

        return new RagAssembly(
            payloads:        $payloads,
            seenIds:         $seenIds,
            contextMode:     $mode,
            freshnessAnchor: null, // set by the warm-up caller (guard step)
            assembledAt:     time(),
        );
    }

    /**
     * @inheritDoc
     */
    public function applyToContext(RagAssembly $assembly, AiPreset $thinking, AiPreset $source): void
    {
        $aggregated = $this->ragAggregator->merge($assembly->payloads);
        $ragText    = $this->ragFormatter->formatAggregated($aggregated);

        $targetIds = array_unique([$source->getId(), $thinking->getId()]);

        foreach ($targetIds as $id) {
            $this->shortcodeManager->registerShortcodeForPreset(
                $id,
                'rag_context',
                'RAG: relevant memories retrieved before this thinking cycle',
                fn () => $ragText
            );
        }
    }

    public function resolveForCycle(
        AiPreset $thinking,
        AiPreset $source,
        bool $extended,
        array $context,
    ): RagAssembly {
        $mode = $extended ? 'extended' : 'normal';

        // 1. Silent hit — inside a silent act. The full assembly is already built;
        // reuse it verbatim, re-formulating and re-retrieving nothing. This is what
        // collapses N silent cycles (Continue runs) into a single assembly.
        $silent = $this->silentCache->get($thinking->getId(), $mode);
        if ($silent !== null) {
            return $silent;
        }

        // 2. Warm hit — first cycle after speech: warm prewarmable + fresh non-prewarmable.
        $warm = $this->warmCache->get($thinking->getId(), $mode);
        if ($warm !== null) {
            $seen  = $warm->seenIds;
            $fresh = $this->assemble(
                thinking:           $thinking,
                source:             $source,
                extended:           $extended,
                onlyConfigIds:      $this->nonPrewarmableConfigIds($source, $extended),
                seenIds:            $seen,
                emitSystemMessages: true,
                context:            $context,
            );

            $full = new RagAssembly(
                payloads:        array_merge($warm->payloads, $fresh->payloads),
                seenIds:         $seen,
                contextMode:     $mode,
                freshnessAnchor: null,
                assembledAt:     time(),
            );

            // Freeze the full assembly for the rest of this silent act.
            $this->silentCache->put($thinking->getId(), $mode, $full);
            return $full;
        }

        // 3. Cold — no cache at all. Full synchronous assembly, then freeze it.
        $seen = [];
        $full = $this->assemble(
            thinking:           $thinking,
            source:             $source,
            extended:           $extended,
            onlyConfigIds:      [],
            seenIds:            $seen,
            emitSystemMessages: true,
            context:            $context,
        );

        $this->silentCache->put($thinking->getId(), $mode, $full);
        return $full;
    }

    /**
     * Config ids that are NOT prewarmable, after mode filtering — the set the
     * real cycle must always assemble fresh (they depend on the incoming message).
     *
     * @return int[]
     */
    private function nonPrewarmableConfigIds(AiPreset $source, bool $extended): array
    {
        return $this->enricherFactory->getOrderedRagConfigs($source)
            ->filter(fn ($config) => $config->activeInMode($extended))
            ->reject(fn ($config) => $config->isPrewarmable())
            ->map(fn ($config) => (int) $config->id)
            ->values()
            ->all();
    }

    /**
     * Assemble the last-known active message window for warm-up, mirroring the
     * context builders' read: active window, non-system, newest-first-limited,
     * then chronological. Cleaned through the same trait the builders use so
     * RAG query formulation sees identically-shaped turns.
     */
    private function assembleActiveWindow(AiPreset $preset, bool $extended): array
    {
        $limit = $this->contextModeResolver->activeContextLimit($preset);

        $messages = $this->messageModel
            ->forPreset($preset->getId())
            ->activeWindow()
            ->where('role', '!=', 'system')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->reverse();

        return $this->buildCleanContextFromMessages($messages);
    }

    /**
     * Persist a per-config UI system message (RAG visibility). Mirrors the
     * createMessage() that used to live inside RagContextEnricher.
     */
    private function persistSystemMessage(?string $text, ?int $presetId): void
    {
        if ($text === null || $text === '' || $presetId === null) {
            return;
        }

        $this->messageModel->create([
            'role'               => 'system',
            'content'            => $text,
            'from_user_id'       => null,
            'preset_id'          => $presetId,
            'is_visible_to_user' => true,
        ]);
    }
}

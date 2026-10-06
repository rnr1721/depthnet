<?php

namespace App\Jobs;

use App\Contracts\Agent\Enricher\EnricherFactoryInterface;
use App\Contracts\Agent\Enricher\Rag\RagAssemblyCacheInterface;
use App\Contracts\Agent\Enricher\Rag\RagPipelineServiceInterface;
use App\Contracts\Agent\ContextModeResolverInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Services\Agent\Traits\ResolvesSourcePresetTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

/**
 * Warm the RAG cache for a preset after the agent has spoken.
 *
 * Assembles ONLY the prewarmable RAG configs (background levels that don't
 * depend on the user's next message) and stores them as a warm assembly, so
 * the next real cycle serves them instantly and assembles only the reactive
 * (non-prewarmable) levels synchronously.
 *
 * Fire-and-forget: never on the critical path. If the user replies before this
 * finishes, the cycle finds no warm entry and assembles synchronously — nothing
 * lost but the optimisation.
 *
 * System messages ARE emitted so each prewarmable level's RAG preset stream
 * shows the warm pass ran — the only observability for background assembly.
 */
class WarmRag implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use ResolvesSourcePresetTrait;

    public function __construct(
        public readonly int $presetId,
    ) {
    }

    public function handle(
        PresetServiceInterface        $presetService,
        RagPipelineServiceInterface   $ragPipeline,
        RagAssemblyCacheInterface     $warmCache,
        EnricherFactoryInterface      $enricherFactory,
        ContextModeResolverInterface  $contextModeResolver,
        LoggerInterface               $logger,
    ): void {
        $preset = $presetService->findById($this->presetId);
        if (!$preset) {
            return;
        }

        // Resolve source the SAME way the context builders do (target_preset_id),
        // so warm and cycle agree on the RAG source. Mismatch here would cache
        // against the wrong substrate.
        $source = $this->resolveSourcePreset($preset);

        $extended = $contextModeResolver->isExtended($preset);
        $mode     = $extended ? 'extended' : 'normal';

        $prewarmableIds = $enricherFactory->getOrderedRagConfigs($source)
            ->filter(fn ($c) => $c->activeInMode($extended))
            ->filter(fn ($c) => $c->isPrewarmable())
            ->map(fn ($c) => (int) $c->id)
            ->values()
            ->all();

        if (empty($prewarmableIds)) {
            return; // nothing prewarmable — leave the cycle cold
        }

        $seen = [];

        $assembly = $ragPipeline->assemble(
            thinking:           $preset,
            source:             $source,
            extended:           $extended,
            onlyConfigIds:      $prewarmableIds,
            seenIds:            $seen,
            emitSystemMessages: true,
            context:            null, // assemble() builds the last-known window itself
        );

        $warmCache->put($preset->getId(), $mode, $assembly);

        $logger->info('WarmRag: cached prewarmable RAG', [
            'preset_id' => $preset->getId(),
            'mode'      => $mode,
            'configs'   => $prewarmableIds,
            'payloads'  => count($assembly->payloads),
        ]);
    }
}

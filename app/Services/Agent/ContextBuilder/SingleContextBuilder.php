<?php

namespace App\Services\Agent\ContextBuilder;

use App\Contracts\Agent\ContextBuilder\ContextBuilderInterface;
use App\Contracts\Agent\Enricher\EnricherFactoryInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\ContextBuilder\Traits\ContentCleaningTrait;

/**
 * Single context builder - simple message processing without cycles.
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
class SingleContextBuilder implements ContextBuilderInterface
{
    use ContentCleaningTrait;

    public function __construct(
        protected Message                          $messageModel,
        protected OptionsServiceInterface          $optionsService,
        protected EnricherFactoryInterface         $enricherFactory,
        protected InputPoolServiceInterface        $inputPoolService,
        protected ShortcodeManagerServiceInterface $shortcodeManager,
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
            'RAG: relevant memories retrieved before this request',
            fn () => implode("\n\n", $ragParts)
        );

        // ── Inner voice — [[inner_voice]] ─────────────────────────────────────
        $voiceEnricher = $this->enricherFactory->makeContextEnricher();
        $voiceBlock    = $voiceEnricher->enrich($sourcePreset, $context, 'single');

        if ($voiceBlock->getResponse()) {
            $voiceText = $this->formatForPlaceholder($voiceBlock->getResponse(), $voiceBlock->getPreset());
            $this->shortcodeManager->registerShortcodeForPreset(
                $preset->getId(),
                'inner_voice',
                'Inner voice: advice, doubt or intuition injected before each request',
                fn () => $voiceText
            );
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

    /**
     * Format text for placeholders before injecting into system prompt.
     */
    protected function formatForPlaceholder(?string $text, ?AiPreset $preset): string
    {
        if (empty($text) || !$preset) {
            return '';
        }

        $cleanText = trim($text);
        $start     = '[' . $preset->getName() . "]\r\n";
        $end       = '[END OF ' . $preset->getName() . "]\r\n";

        return $start . $cleanText . "\r\n" . $end;
    }
}

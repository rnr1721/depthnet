<?php

namespace App\Services\Agent\Enricher;

use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\Enricher\EnricherResponseInterface;
use App\Contracts\Agent\Enricher\RagContextEnricherInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryFactoryInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\DTO\ModelRequestDTO;
use App\Services\Agent\Enricher\EnricherResponse;
use Psr\Log\LoggerInterface;

class RagContextEnricher implements RagContextEnricherInterface
{
    /**
     * Enable verbose debug logging for RAG pipeline.
     * Set to true temporarily when diagnosing issues.
     */
    private bool $debug = false;

    public function __construct(
        protected PresetServiceInterface             $presetService,
        protected PresetRegistryInterface            $presetRegistry,
        protected VectorMemoryFactoryInterface       $vectorMemoryFactory,
        protected MemoryServiceInterface             $memoryService,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected ShortcodeManagerServiceInterface   $shortcodeManagerService,
        protected PluginMetadataServiceInterface     $pluginMetadataService,
        protected OptionsServiceInterface            $optionsService,
        protected LoggerInterface                    $logger,
    ) {
    }

    public function enrich(AiPreset $preset, array $context, ?string $target = null): EnricherResponseInterface
    {
        try {
            if (!$preset->hasRag()) {
                $this->debugLog('skipped — rag_preset_id not set', ['preset_id' => $preset->getId()]);
                return $this->generateEmptyResponse($preset);
            }

            $ragPreset = $this->presetService->findById($preset->rag_preset_id);

            if (!$ragPreset) {
                $this->logger->warning('RAG: preset not found', ['rag_preset_id' => $preset->rag_preset_id]);
                return $this->generateEmptyResponse($preset);
            }

            if (!$ragPreset->isActive()) {
                $this->logger->warning('RAG: preset inactive', ['rag_preset_id' => $preset->rag_preset_id]);
                return $this->generateEmptyResponse($preset, $ragPreset);
            }

            $query = $this->formulateQuery($ragPreset, $preset, $context);

            if (empty($query)) {
                $this->debugLog('empty query, skipping search');
                return $this->generateEmptyResponse($preset, $ragPreset);
            }

            $memoryMode = $this->optionsService->get('agent_rag_vector_memory_mode', 'generic');

            $driver = $memoryMode === 'associative'
                ? VectorMemoryFactoryInterface::DRIVER_ASSOCIATIVE
                : VectorMemoryFactoryInterface::DRIVER_DEFAULT;

            $vectorService = $this->vectorMemoryFactory->make($driver);

            $searchResult = $vectorService->searchVectorMemories($preset, $query, [
                'search_limit' => 5,
                'boost_recent' => true,
            ]);

            $this->logger->debug('RAG enrichment', [
                'query'         => $query,
                'results_count' => count($searchResult['results'] ?? []),
            ]);

            if (!$searchResult['success'] || empty($searchResult['results'])) {
                return $this->generateEmptyResponse($preset);
            }

            $result = $this->formatResults($searchResult['results'], $query);
            return new EnricherResponse($preset, $ragPreset, $result);

        } catch (\Throwable $e) {
            $this->logger->error('RagContextEnricher::enrich error: ' . $e->getMessage(), [
                'main_preset_id' => $preset->getId(),
                'trace'          => $e->getTraceAsString(),
            ]);
            return $this->generateEmptyResponse($preset);
        }
    }

    /**
     * Formulate query
     *
     * @param AiPreset $ragPreset Preset for RAG
     * @param AiPreset $mainPreset Main preset
     * @param array $context Context
     * @return string|null
     */
    protected function formulateQuery(AiPreset $ragPreset, AiPreset $mainPreset, array $context): ?string
    {
        try {
            $contextLimit   = max(1, (int) $mainPreset->getRagContextLimit());
            $recentMessages = collect($context)
                ->filter(fn ($m) => in_array($m['role'] ?? '', ['user', 'assistant', 'thinking', 'command']))
                ->values()
                ->slice(-$contextLimit)
                ->values();

            $this->debugLog('recent messages for query', ['count' => $recentMessages->count()]);

            if ($recentMessages->isEmpty()) {
                return null;
            }

            $conversationText = $recentMessages
                ->map(fn ($m) => strtoupper($m['role']) . ': ' . mb_substr($m['content'] ?? '', 0, 400))
                ->implode("\n");

            $engine = $this->presetRegistry->createInstance($ragPreset->getId());

            $dto = new ModelRequestDTO(
                preset:                    $ragPreset,
                memoryService:             $this->memoryService,
                commandInstructionBuilder: $this->commandInstructionBuilder,
                shortcodeManager:          $this->shortcodeManagerService,
                pluginMetadataService:     $this->pluginMetadataService,
                context:                   [
                    ['role' => 'user', 'content' => $conversationText],
                ],
            );

            $response = $engine->generate($dto);

            $this->debugLog('engine response', [
                'is_error' => $response->isError(),
                'response' => mb_substr($response->getResponse(), 0, 200),
            ]);

            if ($response->isError()) {
                $this->logger->warning('RAG: query formulation failed', [
                    'rag_preset' => $ragPreset->getName(),
                    'error'      => $response->getResponse(),
                ]);
                return null;
            }

            $query = trim(strip_tags($response->getResponse()));
            $query = preg_replace('/\s+/', ' ', $query);

            return mb_substr($query, 0, 200) ?: null;

        } catch (\Throwable $e) {
            $this->logger->error('RagContextEnricher::formulateQuery error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    protected function formatResults(array $results, string $query): string
    {
        $lines = ["[RAG CONTEXT — query: \"{$query}\"]", ''];

        foreach ($results as $i => $result) {
            // 'document' is the key from TfIdfServiceInterface::findSimilar().
            // VectorMemoryAssociativeService also re-adds a 'memory' alias,
            // so we fall back to it for backwards compatibility.
            $memory  = $result['document'] ?? $result['memory'];
            $score   = round(($result['composite_score'] ?? $result['similarity']) * 100, 1);
            $date    = $memory->getCreatedAt()->format('Y-m-d');
            $content = mb_substr($memory->getTextContent(), 0, 600);

            $lines[] = sprintf('%d. [%s | %s%%] %s', $i + 1, $date, $score, $content);
        }

        $lines[] = '';
        $lines[] = '[END RAG CONTEXT]';

        return implode("\n", $lines);
    }

    private function generateEmptyResponse(AiPreset $mainPreset, ?AiPreset $voicePreset = null): EnricherResponseInterface
    {
        return new EnricherResponse($mainPreset, $voicePreset);
    }

    /**
     * Log only when $debug = true.
     */
    private function debugLog(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->logger->debug('RAG: ' . $message, $context);
        }
    }
}

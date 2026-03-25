<?php

namespace App\Services\Agent\Enricher;

use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\Enricher\EnricherResponseInterface;
use App\Contracts\Agent\Enricher\RagContextEnricherInterface;
use App\Contracts\Agent\Journal\JournalServiceInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Agent\Skills\SkillServiceInterface;
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
        protected SkillServiceInterface              $skillService,
        protected JournalServiceInterface            $journalService,
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

            // --- Vector memory search ---
            $memoryMode  = $this->optionsService->get('agent_rag_vector_memory_mode', 'generic');
            $searchLimit = $preset->getRagResults() ?? 5;

            $assocResults   = [];
            $defaultResults = [];
            $seenIds        = [];

            // Associative first (if enabled) — smarter, gets priority
            if ($memoryMode === 'associative') {
                $assocService = $this->vectorMemoryFactory->make(VectorMemoryFactoryInterface::DRIVER_ASSOCIATIVE);
                $assocSearch  = $assocService->searchVectorMemories($preset, $query, [
                    'search_limit' => $searchLimit,
                    'boost_recent' => true,
                ]);

                if ($assocSearch['success'] && !empty($assocSearch['results'])) {
                    $assocResults = $assocSearch['results'];
                    $seenIds      = collect($assocResults)
                        ->map(fn ($r) => ($r['document'] ?? $r['memory'])->id)
                        ->flip()
                        ->toArray();
                }
            }

            // Default TF-IDF always runs — adds results not already found by associative
            $defaultService = $this->vectorMemoryFactory->make(VectorMemoryFactoryInterface::DRIVER_DEFAULT);
            $defaultSearch  = $defaultService->searchVectorMemories($preset, $query, [
                'search_limit' => $searchLimit,
                'boost_recent' => true,
            ]);

            if ($defaultSearch['success'] && !empty($defaultSearch['results'])) {
                foreach ($defaultSearch['results'] as $r) {
                    $id = ($r['document'] ?? $r['memory'])->id;
                    if (!isset($seenIds[$id])) {
                        $defaultResults[] = $r;
                        $seenIds[$id]     = true;
                    }
                }
            }

            $this->logger->debug('RAG enrichment', [
                'query'         => $query,
                'mode'          => $memoryMode,
                'assoc_count'   => count($assocResults),
                'default_count' => count($defaultResults),
            ]);

            // --- Skills search ---
            $skillResults = $this->skillService->searchItemsData($preset, $query, 3);

            // --- Journal search ---
            // Surface relevant past events from the episodic chronicle.
            // Pure semantic search — no date filter here, RAG decides relevance.
            $journalResults = $this->journalService->searchEntries($preset, $query, 3);

            // Nothing found in any source — return empty
            if (empty($assocResults) && empty($defaultResults) && empty($skillResults) && empty($journalResults)) {
                return $this->generateEmptyResponse($preset, $ragPreset);
            }

            $result = $this->formatResults($query, $assocResults, $defaultResults, $skillResults, $journalResults);
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

    /**
     * Format results from all sources into a single RAG context block.
     *
     * @param string $query
     * @param array  $assocResults   Associative vector memory results
     * @param array  $defaultResults TF-IDF vector memory results (deduplicated)
     * @param array  $skillResults   Skill items results
     * @param array  $journalResults Episodic journal entries
     */
    protected function formatResults(
        string $query,
        array $assocResults,
        array $defaultResults,
        array $skillResults,
        array $journalResults = []
    ): string {
        $lines = ["[RAG CONTEXT — query: \"{$query}\"]", ''];

        // Associative memory — found by semantic association
        if (!empty($assocResults)) {
            $lines[] = '[ASSOCIATIVE MEMORY]';
            foreach ($assocResults as $i => $result) {
                $memory  = $result['document'] ?? $result['memory'];
                $score   = round(($result['composite_score'] ?? $result['similarity']) * 100, 1);
                $date    = $memory->getCreatedAt()->format('Y-m-d');
                $content = mb_substr($memory->getTextContent(), 0, 600);
                $lines[] = sprintf('%d. [%s | %s%%] %s', $i + 1, $date, $score, $content);
            }
            $lines[] = '';
        }

        // Default TF-IDF memory — found by keyword similarity
        if (!empty($defaultResults)) {
            $lines[] = '[KEYWORD MEMORY]';
            foreach ($defaultResults as $i => $result) {
                $memory  = $result['document'] ?? $result['memory'];
                $score   = round(($result['composite_score'] ?? $result['similarity']) * 100, 1);
                $date    = $memory->getCreatedAt()->format('Y-m-d');
                $content = mb_substr($memory->getTextContent(), 0, 600);
                $lines[] = sprintf('%d. [%s | %s%%] %s', $i + 1, $date, $score, $content);
            }
            $lines[] = '';
        }

        // Skills — relevant knowledge items
        if (!empty($skillResults)) {
            $lines[] = '[RELEVANT SKILLS]';
            foreach ($skillResults as $item) {
                $lines[] = sprintf(
                    'Skill #%d "%s" — item %d.%d (%s%%): %s',
                    $item['skill_number'],
                    $item['skill_title'],
                    $item['skill_number'],
                    $item['item_number'],
                    $item['similarity_percent'],
                    mb_substr($item['content'], 0, 400)
                );
            }
            $lines[] = '';
        }

        // Journal — relevant past events from the episodic chronicle
        if (!empty($journalResults)) {
            $lines[] = '[RELEVANT JOURNAL ENTRIES]';
            foreach ($journalResults as $entry) {
                $date    = $entry->recorded_at->format('Y-m-d H:i');
                $outcome = $entry->outcome ? " [{$entry->outcome}]" : '';
                $lines[] = sprintf(
                    '#{%d} [%s] [%s]%s %s',
                    $entry->id,
                    $date,
                    $entry->type,
                    $outcome,
                    mb_substr($entry->summary, 0, 300)
                );
            }
            $lines[] = '';
        }

        $lines[] = '[END RAG CONTEXT]';

        return implode("\n", $lines);
    }

    private function generateEmptyResponse(AiPreset $mainPreset, ?AiPreset $voicePreset = null): EnricherResponseInterface
    {
        return new EnricherResponse($mainPreset, $voicePreset);
    }

    private function debugLog(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->logger->debug('RAG: ' . $message, $context);
        }
    }
}

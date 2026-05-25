<?php

namespace App\Services\Agent\Enricher;

use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\Enricher\EnricherResponseInterface;
use App\Contracts\Agent\Enricher\PersonContextEnricherInterface;
use App\Contracts\Agent\Enricher\Rag\RagContentFormatterInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionType;
use App\Contracts\Agent\Enricher\RagContextEnricherInterface;
use App\Contracts\Agent\FileStorage\FileServiceInterface;
use App\Contracts\Agent\Journal\JournalServiceInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Ontology\OntologyServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Agent\Skills\SkillServiceInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryFactoryInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Models\PresetRagConfig;
use App\Services\Agent\DTO\ModelRequestDTO;
use App\Services\Agent\Enricher\Rag\RagData;
use App\Services\Agent\Enricher\Rag\RagItem;
use App\Services\Agent\Enricher\Rag\RagSection;
use App\Services\Agent\Plugins\RagQueryPlugin;
use Psr\Log\LoggerInterface;

/**
 * RagContextEnricher — retrieves data from configured sources and packages it
 * as a structured RagData payload plus a text representation.
 *
 * Pipeline:
 *   1. Formulate queries (agent-provided for primary config, otherwise from RAG preset model)
 *   2. Run retrieval per source (vector memory, journal, ontology, skills, files, persons)
 *   3. Convert results into typed RagSection[] objects (no formatting yet)
 *   4. Hand sections to RagContentFormatter for the UI text representation
 *   5. Return EnricherResponse carrying both text (for UI) and RagData (for aggregation)
 *
 * Compared to the previous implementation:
 *   - formatResults() is gone — formatting lives in RagContentFormatter
 *   - Each source produces typed RagSection objects
 *   - Journal neighbours are fetched here (was: in formatter) so items are
 *     fully populated before they leave the enricher
 */
class RagContextEnricher implements RagContextEnricherInterface
{
    /**
     * Enable verbose debug logging for RAG pipeline.
     */
    private bool $debug = false;

    /**
     * Separators tried in order when splitting a multi-query response from the
     * RAG preset model. First match wins.
     */
    private const QUERY_SEPARATORS = [';', '//', "\n"];

    public function __construct(
        protected PresetServiceInterface             $presetService,
        protected PresetRegistryInterface            $presetRegistry,
        protected VectorMemoryFactoryInterface       $vectorMemoryFactory,
        protected MemoryServiceInterface             $memoryService,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected ShortcodeManagerServiceInterface   $shortcodeManagerService,
        protected PluginMetadataServiceInterface     $pluginMetadataService,
        protected SkillServiceInterface              $skillService,
        protected JournalServiceInterface            $journalService,
        protected FileServiceInterface               $fileService,
        protected PersonContextEnricherInterface     $personEnricher,
        protected OntologyServiceInterface           $ontologyService,
        protected RagContentFormatterInterface       $ragFormatter,
        protected Message                            $messageModel,
        protected LoggerInterface                    $logger,
    ) {
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Legacy single-pass entry point.
     */
    public function enrich(AiPreset $preset, array $context, ?string $target = null): EnricherResponseInterface
    {
        $config = $preset->ragConfigs()->primary()->first()
            ?? $preset->ragConfigs()->ordered()->first();

        if (!$config) {
            $this->debugLog('skipped — no rag configs', ['preset_id' => $preset->getId()]);
            return $this->emptyResponse($preset);
        }

        $seenIds = [];
        return $this->enrichWithConfig($preset, $context, $config, $seenIds);
    }

    /**
     * Multi-RAG pipeline entry point.
     *
     * @param array<string,true> $seenIds  Accumulated dedup state (passed by ref)
     */
    public function enrichWithConfig(
        AiPreset $preset,
        array $context,
        PresetRagConfig $config,
        array &$seenIds = []
    ): EnricherResponseInterface {
        $ragPreset = null;

        try {
            $ragPreset = $this->presetService->findById($config->rag_preset_id);

            if (!$ragPreset) {
                $this->logger->warning('RAG: preset not found', ['rag_preset_id' => $config->rag_preset_id]);
                return $this->emptyResponse($preset);
            }

            if (!$ragPreset->isActive()) {
                $this->logger->warning('RAG: preset inactive', ['rag_preset_id' => $config->rag_preset_id]);
                return $this->emptyResponse($preset, $ragPreset);
            }

            // Agent-provided queries only apply to the primary config
            $queries = $this->formulateQueries($ragPreset, $preset, $context, $config->is_primary);

            if (empty($queries)) {
                $this->debugLog('empty queries, skipping search');
                return $this->emptyResponse($preset, $ragPreset);
            }

            // ── Retrieve from all sources ────────────────────────────────────
            $retrieval = $this->runRetrieval($preset, $context, $config, $queries, $seenIds);

            $this->logRetrieval($config, $queries, $retrieval);

            if ($this->isRetrievalEmpty($retrieval)) {
                return $this->emptyResponse($preset, $ragPreset);
            }

            // ── Build typed sections ─────────────────────────────────────────
            $sections = $this->buildSections($queries, $config, $retrieval);

            if (empty($sections)) {
                return $this->emptyResponse($preset, $ragPreset);
            }

            // ── Package into RagData ─────────────────────────────────────────
            $payload = new RagData(
                queries:        $queries,
                sections:       $sections,
                sourceConfigId: (int) $config->id,
                priority:       (int) ($config->sort_order ?? 0),
            );

            // ── Format text representation for UI message ────────────────────
            $text = $this->ragFormatter->formatIndividual($payload);

            if ($text !== '') {
                $this->createMessage($text, $ragPreset->getId(), 'system');
            }

            return new EnricherResponse(
                mainPreset:   $preset,
                voicePreset:  $ragPreset,
                response:     $text,
                retrievedIds: $seenIds,
                responseData: $payload,
            );

        } catch (\Throwable $e) {
            if ($ragPreset) {
                $this->createMessage($e->getMessage(), $ragPreset->getId(), 'system');
            }
            $this->logger->error('RagContextEnricher::enrichWithConfig error: ' . $e->getMessage(), [
                'main_preset_id' => $preset->getId(),
                'config_id'      => $config->id ?? null,
                'trace'          => $e->getTraceAsString(),
            ]);
            return $this->emptyResponse($preset);
        }
    }

    // ── Retrieval ─────────────────────────────────────────────────────────────

    /**
     * Run all enabled sources and collect their raw results.
     *
     * Returns a structured array keyed by source name. Each value is the raw
     * result data — converted to RagSection objects later in buildSections().
     *
     * @param array<string,true> $seenIds
     */
    private function runRetrieval(
        AiPreset $preset,
        array $context,
        PresetRagConfig $config,
        array $queries,
        array &$seenIds,
    ): array {
        $ragMode     = $config->getRagMode();
        $ragEngine   = $config->getRagEngine();
        $searchLimit = $config->getRagResults();

        $primaryResults        = [];
        $supplementResultsOnce = [];
        $allFlatResults        = [];

        // Vector memory
        if ($config->hasVectorMemory()) {
            [$primaryResults, $supplementResultsOnce] = $this->runVectorSearch(
                $preset,
                $queries[0],
                $ragMode,
                $ragEngine,
                $searchLimit,
                $seenIds
            );

            foreach ($primaryResults as $r) {
                $seenIds['vm:' . ($r['document'] ?? $r['memory'])->id] = true;
            }
            foreach ($supplementResultsOnce as $r) {
                $seenIds['vm:' . ($r['document'] ?? $r['memory'])->id] = true;
            }

            // Flat search per remaining query
            foreach ($queries as $i => $query) {
                $flatService = $this->vectorMemoryFactory->make(VectorMemoryFactoryInterface::MODE_FLAT, $ragEngine);
                $flatSearch  = $flatService->searchVectorMemories($preset, $query, [
                    'search_limit' => $searchLimit,
                    'boost_recent' => true,
                ]);

                if ($flatSearch['success'] ?? false) {
                    foreach ($flatSearch['results'] ?? [] as $r) {
                        $key = 'vm:' . ($r['document'] ?? $r['memory'])->id;
                        if (!isset($seenIds[$key])) {
                            $allFlatResults[] = $r;
                            $seenIds[$key]    = true;
                        }
                    }
                }
            }
        }

        // Journal — fetch entries plus neighbours
        $journalAnchors    = [];
        $journalNeighbours = [];

        if ($config->hasJournal()) {
            foreach ($queries as $query) {
                $hits = $this->journalService->searchEntries($preset, $query, $config->getRagJournalLimit());
                foreach ($hits as $entry) {
                    $key = 'journal:' . $entry->id;
                    if (!isset($seenIds[$key])) {
                        $journalAnchors[] = $entry;
                        $seenIds[$key]    = true;
                    }
                }
            }

            $journalWindow = $config->getRagJournalContextWindow();
            if ($journalWindow > 0 && !empty($journalAnchors)) {
                $anchorIds = array_map(fn ($e) => $e->id, $journalAnchors);
                $neighbours = $this->journalService->fetchNeighbours($preset, $anchorIds, $journalWindow);

                foreach ($neighbours as $id => $entry) {
                    // Skip if this neighbour is already an anchor or already seen
                    $key = 'journal:' . $id;
                    if (!isset($seenIds[$key])) {
                        $journalNeighbours[$id] = $entry;
                        $seenIds[$key]          = true;
                    }
                }
            }
        }

        // Ontology — uses retrieved text from other sources
        $ontologyResults = [];

        if ($config->hasOntology()) {
            $retrievedText = $this->collectRetrievedText(
                $primaryResults,
                $supplementResultsOnce,
                $allFlatResults,
                $journalAnchors,
                $queries,
            );

            if (!empty($retrievedText)) {
                $mentionedNodes = $this->ontologyService->findMentionedNodes($preset, $retrievedText);

                foreach ($mentionedNodes as $node) {
                    $key = 'ontology:' . $node->id;
                    if (!isset($seenIds[$key])) {
                        $snapshot = $this->ontologyService->getSnapshot($preset, [
                            'node'  => $node->canonical_name,
                            'depth' => 1,
                        ]);
                        if ($snapshot['success']) {
                            $ontologyResults[] = [
                                'node_id'  => $node->id,
                                'snapshot' => $snapshot['message'],
                            ];
                            $seenIds[$key] = true;
                        }
                    }
                }
            }
        }

        // Skills
        $skillResults = [];

        if ($config->hasSkills()) {
            $skillResults = $this->skillService->searchItemsData(
                $preset,
                $queries[0],
                $config->getRagSkillsLimit()
            );

            foreach ($skillResults as $item) {
                $seenIds['skill:' . $item['skill_number'] . '.' . $item['item_number']] = true;
            }
        }

        // Files
        $fileResults = [];

        if ($config->hasFiles()) {
            foreach ($queries as $query) {
                $result = $this->fileService->search(
                    preset:    $preset,
                    query:     $query,
                    limit:     $config->getRagResults(),
                    threshold: 0.2,
                );

                if ($result['success'] ?? false) {
                    foreach ($result['results'] ?? [] as $r) {
                        $key = 'file_chunk:' . $r['chunk']->id;
                        if (!isset($seenIds[$key])) {
                            $fileResults[] = $r;
                            $seenIds[$key] = true;
                        }
                    }
                }
            }
        }

        // Persons — now returns a RagSection directly
        $personsSection = null;

        if ($config->hasPersons()) {
            $personsSection = $this->personEnricher->enrichAsSection($preset, $context);
        }

        return [
            'mode'              => $ragMode,
            'engine'            => $ragEngine,
            'primaryResults'    => $primaryResults,
            'supplementResults' => $supplementResultsOnce,
            'flatResults'       => $allFlatResults,
            'journalAnchors'    => $journalAnchors,
            'journalNeighbours' => $journalNeighbours,
            'ontologyResults'   => $ontologyResults,
            'skillResults'      => $skillResults,
            'fileResults'       => $fileResults,
            'personsSection'    => $personsSection,
        ];
    }

    private function isRetrievalEmpty(array $retrieval): bool
    {
        return empty($retrieval['primaryResults'])
            && empty($retrieval['supplementResults'])
            && empty($retrieval['flatResults'])
            && empty($retrieval['journalAnchors'])
            && empty($retrieval['ontologyResults'])
            && empty($retrieval['skillResults'])
            && empty($retrieval['fileResults'])
            && $retrieval['personsSection'] === null;
    }

    // ── Section building ──────────────────────────────────────────────────────

    /**
     * Convert raw retrieval results into typed RagSection objects.
     *
     * @return RagSectionInterface[]
     */
    private function buildSections(
        array $queries,
        PresetRagConfig $config,
        array $retrieval,
    ): array {
        $sections = [];
        $renderOptions = $this->buildRenderOptions($config);

        // ── Memory: primary
        if (!empty($retrieval['primaryResults'])) {
            $sections[] = $this->buildPrimaryMemorySection(
                $retrieval['primaryResults'],
                $retrieval['mode'],
                $retrieval['engine'],
                $renderOptions,
            );
        }

        // ── Memory: keyword fallback (tfidf_fallback from supplement)
        if (!empty($retrieval['supplementResults'])) {
            $sections[] = new RagSection(
                type:          RagSectionType::MemoryKeywordFallback->value,
                items:         $this->mapMemoryItems($retrieval['supplementResults']),
                renderOptions: $renderOptions,
            );
        }

        // ── Memory: additional / multi-query
        if (!empty($retrieval['flatResults'])) {
            $isMultiQuery = count($queries) > 1;
            $sections[] = new RagSection(
                type:          RagSectionType::MemoryAdditional->value,
                items:         $this->mapMemoryItems($retrieval['flatResults']),
                label:         $isMultiQuery ? '[MULTI-QUERY MEMORY]' : null,
                renderOptions: $renderOptions,
            );
        }

        // ── Skills
        if (!empty($retrieval['skillResults'])) {
            $sections[] = new RagSection(
                type:          RagSectionType::Skills->value,
                items:         $this->mapSkillItems($retrieval['skillResults']),
                renderOptions: $renderOptions,
            );
        }

        // ── Journal
        if (!empty($retrieval['journalAnchors'])) {
            $sections[] = new RagSection(
                type:          RagSectionType::Journal->value,
                items:         $this->mapJournalItems($retrieval['journalAnchors'], $retrieval['journalNeighbours']),
                renderOptions: $renderOptions,
            );
        }

        // ── Ontology
        if (!empty($retrieval['ontologyResults'])) {
            $sections[] = new RagSection(
                type:          RagSectionType::Ontology->value,
                items:         $this->mapOntologyItems($retrieval['ontologyResults']),
                renderOptions: $renderOptions,
            );
        }

        // ── Files
        if (!empty($retrieval['fileResults'])) {
            $sections[] = new RagSection(
                type:          RagSectionType::Files->value,
                items:         $this->mapFileItems($retrieval['fileResults']),
                renderOptions: $renderOptions,
            );
        }

        // ── Persons (already a complete section from PersonContextEnricher)
        if ($retrieval['personsSection'] !== null) {
            $sections[] = $retrieval['personsSection'];
        }

        return $sections;
    }

    /**
     * Pick the right memory section type based on mode + engine.
     */
    private function buildPrimaryMemorySection(
        array $results,
        string $mode,
        string $engine,
        array $renderOptions,
    ): RagSectionInterface {
        $type = match (true) {
            $mode === VectorMemoryFactoryInterface::MODE_ASSOCIATIVE && $engine === VectorMemoryFactoryInterface::ENGINE_EMBEDDING => RagSectionType::MemorySemanticAssociative,
            $mode === VectorMemoryFactoryInterface::MODE_FLAT        && $engine === VectorMemoryFactoryInterface::ENGINE_EMBEDDING => RagSectionType::MemorySemantic,
            $mode === VectorMemoryFactoryInterface::MODE_ASSOCIATIVE                                                                => RagSectionType::MemoryAssociative,
            default                                                                                                                  => RagSectionType::MemoryKeyword,
        };

        return new RagSection(
            type:          $type->value,
            items:         $this->mapMemoryItems($results),
            renderOptions: $renderOptions,
        );
    }

    /**
     * @return RagItem[]
     */
    private function mapMemoryItems(array $results): array
    {
        $items = [];

        foreach ($results as $r) {
            $memory = $r['document'] ?? $r['memory'];
            $score  = $r['composite_score'] ?? $r['similarity'] ?? null;

            $items[] = new RagItem(
                dedupKey: 'vm:' . $memory->id,
                content:  $memory->getTextContent(),
                score:    $score !== null ? (float) $score : null,
                metadata: array_filter([
                    'document'        => $r['document'] ?? null,
                    'memory'          => $r['memory'] ?? null,
                    'composite_score' => $r['composite_score'] ?? null,
                    'source'          => $r['source'] ?? null,
                ], fn ($v) => $v !== null),
            );
        }

        return $items;
    }

    /**
     * @return RagItem[]
     */
    private function mapSkillItems(array $skillResults): array
    {
        $items = [];

        foreach ($skillResults as $item) {
            $similarityPercent = (float) ($item['similarity_percent'] ?? 0);

            $items[] = new RagItem(
                dedupKey: 'skill:' . $item['skill_number'] . '.' . $item['item_number'],
                content:  $item['content'] ?? '',
                score:    $similarityPercent / 100.0,
                metadata: [
                    'skill_number'       => $item['skill_number'] ?? 0,
                    'item_number'        => $item['item_number'] ?? 0,
                    'skill_title'        => $item['skill_title'] ?? '',
                    'similarity_percent' => $similarityPercent,
                ],
            );
        }

        return $items;
    }

    /**
     * Build journal items from anchors + neighbours, sorted chronologically.
     *
     * @return RagItem[]
     */
    private function mapJournalItems(array $anchors, array $neighbours): array
    {
        $timeline = [];

        foreach ($anchors as $entry) {
            $timeline[$entry->id] = ['entry' => $entry, 'is_anchor' => true];
        }
        foreach ($neighbours as $id => $entry) {
            if (!isset($timeline[$id])) {
                $timeline[$id] = ['entry' => $entry, 'is_anchor' => false];
            }
        }

        uasort($timeline, fn ($a, $b) => $a['entry']->recorded_at <=> $b['entry']->recorded_at);

        $items = [];

        foreach ($timeline as ['entry' => $entry, 'is_anchor' => $isAnchor]) {
            $items[] = new RagItem(
                dedupKey: 'journal:' . $entry->id,
                content:  $entry->summary,
                score:    $isAnchor ? null : null, // journal entries aren't ranked by score
                metadata: [
                    'entry'     => $entry,
                    'is_anchor' => $isAnchor,
                ],
            );
        }

        return $items;
    }

    /**
     * @return RagItem[]
     */
    private function mapOntologyItems(array $ontologyResults): array
    {
        $items = [];

        foreach ($ontologyResults as $result) {
            $items[] = new RagItem(
                dedupKey: 'ontology:' . ($result['node_id'] ?? uniqid('ont_')),
                content:  $result['snapshot'] ?? '',
            );
        }

        return $items;
    }

    /**
     * @return RagItem[]
     */
    private function mapFileItems(array $fileResults): array
    {
        $items = [];

        foreach ($fileResults as $r) {
            $chunk      = $r['chunk'];
            $similarity = (float) ($r['similarity'] ?? 0);

            $items[] = new RagItem(
                dedupKey: 'file_chunk:' . $chunk->id,
                content:  $chunk->content ?? '',
                score:    $similarity,
                metadata: [
                    'chunk'      => $chunk,
                    'similarity' => $similarity,
                ],
            );
        }

        return $items;
    }

    /**
     * Common render options derived from PresetRagConfig.
     */
    private function buildRenderOptions(PresetRagConfig $config): array
    {
        return [
            'max_content_limit'      => $config->getRagContentLimit(),
            'show_relative_date'     => $config->getRagRelativeDates(),
            'journal_context_window' => $config->getRagJournalContextWindow(),
        ];
    }

    // ── Vector search ─────────────────────────────────────────────────────────

    /**
     * Run associative (or flat) vector memory search for a single query.
     *
     * @param array<string,true> $seenIds
     * @return array{array, array}
     */
    private function runVectorSearch(
        AiPreset $preset,
        string   $query,
        string   $mode,
        string   $engine,
        int      $searchLimit,
        array    $seenIds,
    ): array {
        $primaryService = $this->vectorMemoryFactory->make($mode, $engine);
        $primarySearch  = $primaryService->searchVectorMemories($preset, $query, [
            'search_limit' => $searchLimit,
            'boost_recent' => true,
        ]);

        $primaryResults = [];
        $localSeen      = [];

        if ($primarySearch['success'] ?? false) {
            foreach ($primarySearch['results'] ?? [] as $r) {
                $key = 'vm:' . ($r['document'] ?? $r['memory'])->id;
                if (!isset($seenIds[$key])) {
                    $primaryResults[] = $r;
                    $localSeen[$key]  = true;
                }
            }
        }

        $supplementResults = [];

        if ($mode === VectorMemoryFactoryInterface::MODE_ASSOCIATIVE) {
            $flatService = $this->vectorMemoryFactory->make(VectorMemoryFactoryInterface::MODE_FLAT, $engine);
            $flatSearch  = $flatService->searchVectorMemories($preset, $query, [
                'search_limit' => $searchLimit,
                'boost_recent' => true,
            ]);

            if ($flatSearch['success'] ?? false) {
                foreach ($flatSearch['results'] ?? [] as $r) {
                    $key = 'vm:' . ($r['document'] ?? $r['memory'])->id;
                    if (!isset($seenIds[$key]) && !isset($localSeen[$key])) {
                        $supplementResults[] = $r;
                        $localSeen[$key]     = true;
                    }
                }
            }
        }

        // Separate tfidf_fallback records from primary into supplement
        $embeddingFallback = [];
        $cleanPrimary      = [];

        foreach ($primaryResults as $r) {
            if (($r['source'] ?? '') === 'tfidf_fallback') {
                $embeddingFallback[] = $r;
            } else {
                $cleanPrimary[] = $r;
            }
        }

        return [$cleanPrimary, array_merge($supplementResults, $embeddingFallback)];
    }

    // ── Query formulation ─────────────────────────────────────────────────────

    /**
     * Resolve the list of search queries for this enrichment pass.
     *
     * @return string[]|null
     */
    protected function formulateQueries(
        AiPreset $ragPreset,
        AiPreset $mainPreset,
        array    $context,
        bool     $isPrimary,
    ): ?array {
        // Agent-provided queries only apply to the primary config
        if ($isPrimary) {
            $pendingRaw = $this->pluginMetadataService->get(
                $mainPreset,
                RagQueryPlugin::PLUGIN_NAME,
                RagQueryPlugin::META_KEY,
            );

            if (!empty($pendingRaw)) {
                $this->pluginMetadataService->remove(
                    $mainPreset,
                    RagQueryPlugin::PLUGIN_NAME,
                    RagQueryPlugin::META_KEY,
                );

                $decoded = json_decode($pendingRaw, true);
                $queries = is_array($decoded) ? $decoded : [$pendingRaw];
                $queries = $this->sanitizeQueries($queries);

                if (!empty($queries)) {
                    $this->debugLog('using agent-provided RAG queries', ['queries' => $queries]);
                    return $queries;
                }
            }
        }

        // Model-formulated queries
        try {
            $contextLimit   = max(1, (int) $ragPreset->getMaxContextLimit());
            $recentMessages = collect($context)
                ->filter(fn ($m) => in_array($m['role'] ?? '', ['user', 'assistant', 'thinking', 'command']))
                ->values()
                ->slice(-$contextLimit)
                ->values();

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

            if ($response->isError()) {
                $this->logger->warning('RAG: query formulation failed', [
                    'rag_preset' => $ragPreset->getName(),
                    'error'      => $response->getResponse(),
                ]);
                return null;
            }

            $raw     = trim(strip_tags($response->getResponse()));
            $queries = $this->sanitizeQueries($this->splitQueryResponse($raw));

            return !empty($queries) ? $queries : null;

        } catch (\Throwable $e) {
            $this->logger->error('RagContextEnricher::formulateQueries error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * @return string[]
     */
    private function splitQueryResponse(string $response): array
    {
        foreach (self::QUERY_SEPARATORS as $sep) {
            if (str_contains($response, $sep)) {
                return array_values(array_filter(
                    array_map('trim', explode($sep, $response)),
                    fn ($q) => $q !== ''
                ));
            }
        }

        return [$response];
    }

    /**
     * @param  string[] $queries
     * @return string[]
     */
    private function sanitizeQueries(array $queries): array
    {
        $result = [];

        foreach ($queries as $q) {
            $q = trim($q);
            $q = trim($q, "\"'«»\u{201C}\u{201D}\u{2018}\u{2019}");
            $q = preg_replace('/\s+/', ' ', $q);
            $q = mb_substr($q, 0, 200);

            if ($q !== '') {
                $result[] = $q;
            }
        }

        return $result;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function createMessage(string $content, int $presetId, string $role): Message
    {
        return $this->messageModel->create([
            'role'               => 'system',
            'content'            => $content,
            'from_user_id'       => null,
            'preset_id'          => $presetId,
            'is_visible_to_user' => true,
        ]);
    }

    private function emptyResponse(AiPreset $mainPreset, ?AiPreset $ragPreset = null): EnricherResponseInterface
    {
        return new EnricherResponse($mainPreset, $ragPreset);
    }

    private function debugLog(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->logger->debug('RAG: ' . $message, $context);
        }
    }

    private function logRetrieval(PresetRagConfig $config, array $queries, array $retrieval): void
    {
        $this->logger->debug('RAG enrichment', [
            'config_id'        => $config->id,
            'is_primary'       => $config->is_primary,
            'queries'          => $queries,
            'mode'             => $retrieval['mode'] ?? null,
            'engine'           => $retrieval['engine'] ?? null,
            'primary_count'    => count($retrieval['primaryResults']),
            'supplement_count' => count($retrieval['supplementResults']),
            'flat_count'       => count($retrieval['flatResults']),
            'journal_count'    => count($retrieval['journalAnchors']),
            'skill_count'      => count($retrieval['skillResults']),
            'ontology_count'   => count($retrieval['ontologyResults']),
            'file_count'       => count($retrieval['fileResults']),
            'has_persons'      => $retrieval['personsSection'] !== null,
        ]);
    }

    private function collectRetrievedText(
        array $primary,
        array $supplement,
        array $flat,
        array $journal,
        array $queries,
    ): string {
        $parts = $queries;

        foreach (array_merge($primary, $supplement, $flat) as $r) {
            $parts[] = ($r['document'] ?? $r['memory'])->getTextContent();
        }

        foreach ($journal as $entry) {
            $parts[] = $entry->summary;
            if ($entry->details) {
                $parts[] = $entry->details;
            }
        }

        return implode(' ', $parts);
    }
}

<?php

namespace App\Services\Agent\Enricher;

use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\Enricher\EnricherResponseInterface;
use App\Contracts\Agent\Enricher\PersonContextEnricherInterface;
use App\Contracts\Agent\Enricher\RagContextEnricherInterface;
use App\Contracts\Agent\Journal\JournalServiceInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Agent\Skills\SkillServiceInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryFactoryInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Models\PresetRagConfig;
use App\Services\Agent\DTO\ModelRequestDTO;
use App\Services\Agent\Plugins\RagQueryPlugin;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;

class RagContextEnricher implements RagContextEnricherInterface
{
    /**
     * Enable verbose debug logging for RAG pipeline.
     * Set to true temporarily when diagnosing issues.
     */
    private bool $debug = false;

    /**
     * Separators tried in order when splitting a multi-query response from the
     * RAG preset model. First match wins.
     */
    private const QUERY_SEPARATORS = ['|', ';', '//', "\n"];

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
        protected PersonContextEnricherInterface     $personEnricher,
        protected Message                            $messageModel,
        protected LoggerInterface                    $logger,
    ) {
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Legacy single-pass entry point.
     *
     * Loads the first ragConfig from the preset and delegates to enrichWithConfig().
     * All existing callers continue to work without changes.
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
     * Called by context builders for each PresetRagConfig in order.
     * $seenIds is passed by reference so the caller accumulates dedup state
     * across the whole pipeline without extra bookkeeping.
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

            $ragMode      = $config->getRagMode();
            $ragEngine    = $config->getRagEngine();
            $searchLimit  = $config->getRagResults();
            $showRelative = $config->getRagRelativeDates();
            $journalWindow = $config->getRagJournalContextWindow();

            // ── Vector memory ─────────────────────────────────────────────────
            $primaryResults       = [];
            $supplementResultsOnce = [];
            $allFlatResults       = [];

            if ($config->hasVectorMemory()) {
                [$primaryResults, $supplementResultsOnce] = $this->runVectorSearch(
                    $preset,
                    $queries[0],
                    $ragMode,
                    $ragEngine,
                    $searchLimit,
                    $seenIds
                );

                // Seed seenIds from primary results
                foreach ($primaryResults as $r) {
                    $seenIds['vm:' . ($r['document'] ?? $r['memory'])->id] = true;
                }
                foreach ($supplementResultsOnce as $r) {
                    $seenIds['vm:' . ($r['document'] ?? $r['memory'])->id] = true;
                }

                // Flat search per query (skip first — already done in runVectorSearch)
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

            // ── Journal ───────────────────────────────────────────────────────
            $allJournalResults = [];

            if ($config->hasJournal()) {
                foreach ($queries as $query) {
                    $hits = $this->journalService->searchEntries($preset, $query, $config->getRagJournalLimit());
                    foreach ($hits as $entry) {
                        $key = 'journal:' . $entry->id;
                        if (!isset($seenIds[$key])) {
                            $allJournalResults[] = $entry;
                            $seenIds[$key]       = true;
                        }
                    }
                }
            }

            // ── Skills ────────────────────────────────────────────────────────
            $skillResults = [];

            if ($config->hasSkills()) {
                $skillResults = $this->skillService->searchItemsData(
                    $preset,
                    $queries[0],
                    $config->getRagSkillsLimit()
                );

                foreach ($skillResults as $item) {
                    $seenIds['skill:' . $item['item_id']] = true;
                }
            }

            // ── Persons ───────────────────────────────────────────────────────
            $personsBlock = null;

            if ($config->hasPersons()) {
                $personsResponse = $this->personEnricher->enrich($preset, $context);
                $personsBlock    = $personsResponse->getResponse();
            }

            $this->logger->debug('RAG enrichment', [
                'config_id'        => $config->id,
                'is_primary'       => $config->is_primary,
                'queries'          => $queries,
                'mode'             => $ragMode,
                'engine'           => $ragEngine,
                'primary_count'    => count($primaryResults),
                'supplement_count' => count($supplementResultsOnce),
                'flat_count'       => count($allFlatResults),
                'journal_count'    => count($allJournalResults),
                'skill_count'      => count($skillResults),
                'has_persons'      => $personsBlock !== null,
            ]);

            if (
                empty($primaryResults) &&
                empty($supplementResultsOnce) &&
                empty($allFlatResults) &&
                empty($skillResults) &&
                empty($allJournalResults) &&
                $personsBlock === null
            ) {
                return $this->emptyResponse($preset, $ragPreset);
            }

            $result = $this->formatResults(
                preset:                 $preset,
                queries:                $queries,
                mode:                   $ragMode,
                engine:                 $ragEngine,
                primaryResults:         $primaryResults,
                supplementResultsOnce:  $supplementResultsOnce,
                flatResults:            $allFlatResults,
                skillResults:           $skillResults,
                journalResults:         $allJournalResults,
                personsBlock:           $personsBlock,
                maxContentLimit:        $config->getRagContentLimit(),
                showRelativeDate:       $showRelative,
                journalContextWindow:   $journalWindow,
            );

            $this->createMessage($result, $ragPreset->getId(), 'system');

            return new EnricherResponse($preset, $ragPreset, $result, $seenIds);

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

    // ── Vector search ─────────────────────────────────────────────────────────

    /**
     * Run associative (or flat) vector memory search for a single query.
     * Filters out records already present in $seenIds.
     *
     * Returns [$primaryResults, $supplementResults].
     *
     * @param  array<string,true> $seenIds  Already-retrieved keys (read-only here;
     *                                      caller updates seenIds after this call)
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
     * Priority:
     *   1. Agent-provided queries via RagQueryPlugin — only for primary config.
     *   2. RAG preset model response, split on common separators.
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
     * Split a raw model response into individual query strings.
     *
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
     * Sanitize raw query strings.
     *
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

    // ── Result formatting ─────────────────────────────────────────────────────

    /**
     * Format all sources into a single RAG context block.
     */
    protected function formatResults(
        AiPreset $preset,
        array    $queries,
        string   $mode,
        string   $engine,
        array    $primaryResults,
        array    $supplementResultsOnce,
        array    $flatResults,
        array    $skillResults,
        array    $journalResults,
        ?string  $personsBlock,
        int      $maxContentLimit,
        bool     $showRelativeDate,
        int      $journalContextWindow,
    ): string {
        $queryHeader = count($queries) === 1
            ? "query: \"{$queries[0]}\""
            : 'queries: ' . implode(' | ', array_map(fn ($q) => "\"{$q}\"", $queries));

        $lines = ["[RAG CONTEXT — {$queryHeader}]", ''];

        if (!empty($primaryResults)) {
            $lines[] = $this->primarySectionLabel($mode, $engine);
            foreach ($primaryResults as $i => $result) {
                $lines[] = $this->formatMemoryLine($i + 1, $result, $maxContentLimit, $showRelativeDate, composite: true);
            }
            $lines[] = '';
        }

        if (!empty($supplementResultsOnce)) {
            $lines[] = '[KEYWORD MEMORY — no embedding yet]';
            foreach ($supplementResultsOnce as $i => $result) {
                $lines[] = $this->formatMemoryLine($i + 1, $result, $maxContentLimit, $showRelativeDate, composite: false);
            }
            $lines[] = '';
        }

        if (!empty($flatResults)) {
            $lines[] = count($queries) > 1 ? '[MULTI-QUERY MEMORY]' : '[ADDITIONAL MEMORY]';
            foreach ($flatResults as $i => $result) {
                $lines[] = $this->formatMemoryLine($i + 1, $result, $maxContentLimit, $showRelativeDate, composite: false);
            }
            $lines[] = '';
        }

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
                    mb_substr($item['content'], 0, $maxContentLimit)
                );
            }
            $lines[] = '';
        }

        if (!empty($journalResults)) {
            $lines[] = '[RELEVANT JOURNAL ENTRIES]';

            $anchorIds  = array_map(fn ($e) => $e->id, $journalResults);
            $neighbours = $journalContextWindow > 0
                ? $this->journalService->fetchNeighbours($preset, $anchorIds, $journalContextWindow)
                : [];

            $timeline = [];
            foreach ($journalResults as $entry) {
                $timeline[$entry->id] = ['entry' => $entry, 'is_anchor' => true];
            }
            foreach ($neighbours as $id => $entry) {
                $timeline[$id] = ['entry' => $entry, 'is_anchor' => false];
            }

            uasort($timeline, fn ($a, $b) => $a['entry']->recorded_at <=> $b['entry']->recorded_at);

            foreach ($timeline as ['entry' => $entry, 'is_anchor' => $isAnchor]) {
                $date = $entry->recorded_at->format('Y-m-d H:i');
                if ($showRelativeDate) {
                    $date .= ' (' . $this->formatRelativeDate($entry->recorded_at) . ')';
                }

                if ($isAnchor) {
                    $outcome = $entry->outcome ? " [{$entry->outcome}]" : '';
                    $lines[] = sprintf(
                        '★ #{%d} [%s] [%s]%s %s',
                        $entry->id,
                        $date,
                        $entry->type,
                        $outcome,
                        mb_substr($entry->summary, 0, $maxContentLimit)
                    );
                } else {
                    $lines[] = sprintf(
                        '  [ctx] #{%d} [%s] [%s] %s',
                        $entry->id,
                        $date,
                        $entry->type,
                        mb_substr($entry->summary, 0, $maxContentLimit)
                    );
                }
            }

            $lines[] = '';
        }

        // Persons block appended last — it has its own header/footer
        if (!empty($personsBlock)) {
            $lines[] = $personsBlock;
            $lines[] = '';
        }

        $lines[] = '[END RAG CONTEXT]';

        return implode("\n", $lines);
    }

    /**
     * Format a single memory result line.
     */
    private function formatMemoryLine(
        int    $num,
        array  $result,
        int    $maxContentLimit,
        bool   $showRelativeDate,
        bool   $composite,
    ): string {
        $memory    = $result['document'] ?? $result['memory'];
        $score     = round((($composite ? ($result['composite_score'] ?? null) : null) ?? $result['similarity']) * 100, 1);
        $content   = mb_substr($memory->getTextContent(), 0, $maxContentLimit);
        $createdAt = $memory->getCreatedAt();
        $dateStr   = $createdAt->format('Y-m-d');

        if ($showRelativeDate) {
            $dateStr .= ' (' . $this->formatRelativeDate($createdAt) . ')';
        }

        return sprintf('%d. [%s | %s%%] %s', $num, $dateStr, $score, $content);
    }

    /**
     * Human-readable section label for the primary results block.
     */
    private function primarySectionLabel(string $mode, string $engine): string
    {
        return match (true) {
            $mode === VectorMemoryFactoryInterface::MODE_ASSOCIATIVE && $engine === VectorMemoryFactoryInterface::ENGINE_EMBEDDING => '[SEMANTIC ASSOCIATIVE MEMORY]',
            $mode === VectorMemoryFactoryInterface::MODE_FLAT        && $engine === VectorMemoryFactoryInterface::ENGINE_EMBEDDING => '[SEMANTIC MEMORY]',
            $mode === VectorMemoryFactoryInterface::MODE_ASSOCIATIVE                                                               => '[ASSOCIATIVE MEMORY]',
            default                                                                                                                 => '[KEYWORD MEMORY]',
        };
    }

    private function formatRelativeDate(\DateTimeInterface|Carbon $date): string
    {
        $diff = abs(now()->diffInSeconds($date, false));

        return match (true) {
            $diff < 60         => 'just now',
            $diff < 3600       => (int) ($diff / 60) . 'm ago',
            $diff < 86400      => (int) ($diff / 3600) . 'h ago',
            $diff < 86400 * 7  => (int) ($diff / 86400) . 'd ago',
            $diff < 86400 * 30 => (int) ($diff / (86400 * 7)) . 'w ago',
            default            => (int) ($diff / (86400 * 30)) . 'mo ago',
        };
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
}

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
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\DTO\ModelRequestDTO;
use App\Services\Agent\Enricher\EnricherResponse;
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
     * Append relative age (e.g. "3 days ago", "just now") next to absolute dates.
     * Toggle via preset setting — injected before formatResults() is called.
     */
    private bool $showRelativeDate = true;

    private int $journalContextWindow = 3; // 0 = off, 1+ = neighbours each side

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
        protected Message                            $messageModel,
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

            // Returns a non-empty array of query strings, or null if nothing to search.
            $queries = $this->formulateQueries($ragPreset, $preset, $context);

            if (empty($queries)) {
                $this->debugLog('empty queries, skipping search');
                return $this->generateEmptyResponse($preset, $ragPreset);
            }

            $this->showRelativeDate     = $preset->getRagRelativeDates();
            $this->journalContextWindow = $preset->getRagJournalContextWindow();

            $ragMode   = $preset->getRagMode();
            $ragEngine = $preset->getRagEngine();
            $searchLimit = $preset->getRagResults() ?? 5;

            // ── Sources that run ONCE regardless of query count ───────────────
            // Associative traversal is expensive (graph construction over all vectors),
            // so we run it only for the first / primary query.
            // Skills and persons are context-level, not query-level.
            [$primaryResults, $supplementResultsOnce] = $this->runVectorSearch(
                $preset,
                $queries[0],
                $ragMode,
                $ragEngine,
                $searchLimit
            );

            $skillResults = $this->skillService->searchItemsData($preset, $queries[0], $preset->getRagSkillsLimit());

            // ── Sources that run PER QUERY (flat memory + journal) ────────────
            // seenIds is shared across all iterations for deduplication.
            $seenIds         = [];
            $allFlatResults  = [];
            $allJournalResults = [];

            // Seed seenIds from the already-retrieved primary results so we
            // don't surface the same records in flat search.
            foreach ($primaryResults as $r) {
                $seenIds[($r['document'] ?? $r['memory'])->id] = true;
            }
            foreach ($supplementResultsOnce as $r) {
                $seenIds[($r['document'] ?? $r['memory'])->id] = true;
            }

            foreach ($queries as $query) {
                // Flat memory search
                $flatService = $this->vectorMemoryFactory->make(VectorMemoryFactoryInterface::MODE_FLAT, $ragEngine);
                $flatSearch  = $flatService->searchVectorMemories($preset, $query, [
                    'search_limit' => $searchLimit,
                    'boost_recent' => true,
                ]);

                if ($flatSearch['success'] ?? false) {
                    foreach ($flatSearch['results'] ?? [] as $r) {
                        $id = ($r['document'] ?? $r['memory'])->id;
                        if (!isset($seenIds[$id])) {
                            $allFlatResults[] = $r;
                            $seenIds[$id]     = true;
                        }
                    }
                }

                // Journal search
                $journalHits = $this->journalService->searchEntries($preset, $query, $preset->getRagJournalLimit());
                foreach ($journalHits as $entry) {
                    if (!isset($seenIds['journal_' . $entry->id])) {
                        $allJournalResults[]               = $entry;
                        $seenIds['journal_' . $entry->id] = true;
                    }
                }
            }

            $this->logger->debug('RAG enrichment', [
                'queries'          => $queries,
                'mode'             => $ragMode,
                'engine'           => $ragEngine,
                'primary_count'    => count($primaryResults),
                'supplement_count' => count($supplementResultsOnce),
                'flat_count'       => count($allFlatResults),
                'journal_count'    => count($allJournalResults),
                'skill_count'      => count($skillResults),
            ]);

            if (
                empty($primaryResults) &&
                empty($supplementResultsOnce) &&
                empty($allFlatResults) &&
                empty($skillResults) &&
                empty($allJournalResults)
            ) {
                return $this->generateEmptyResponse($preset, $ragPreset);
            }

            $maxContentLimit = $preset->getRagContentLimit() ?? 400;

            $result = $this->formatResults(
                $preset,
                $queries,
                $ragMode,
                $ragEngine,
                $primaryResults,
                $supplementResultsOnce,
                $allFlatResults,
                $skillResults,
                $allJournalResults,
                $maxContentLimit
            );

            // Create message in RAG preset for transparency with results
            $this->createMessage($result, $ragPreset->getId(), 'system');

            return new EnricherResponse($preset, $ragPreset, $result);

        } catch (\Throwable $e) {
            $this->createMessage($e->getMessage(), $ragPreset->getId(), 'system');
            $this->logger->error('RagContextEnricher::enrich error: ' . $e->getMessage(), [
                'main_preset_id' => $preset->getId(),
                'trace'          => $e->getTraceAsString(),
            ]);
            return $this->generateEmptyResponse($preset);
        }
    }

    // ── Vector search ─────────────────────────────────────────────────────────

    /**
     * Run associative (or flat) vector memory search for a single query.
     *
     * Returns [$primaryResults, $supplementResults]:
     * - primary  = associative chain (or flat when mode=flat)
     * - supplement = flat dedup complement + tfidf_fallback records
     *
     * Used once per enrichment cycle (for the primary query only).
     *
     * @return array{array, array}
     */
    private function runVectorSearch(
        AiPreset $preset,
        string   $query,
        string   $mode,
        string   $engine,
        int      $searchLimit,
    ): array {
        // ── Primary search (associative or flat) ──────────────────────────────
        $primaryService = $this->vectorMemoryFactory->make($mode, $engine);
        $primarySearch  = $primaryService->searchVectorMemories($preset, $query, [
            'search_limit' => $searchLimit,
            'boost_recent' => true,
        ]);

        $primaryResults = [];
        $seenIds        = [];

        if ($primarySearch['success'] ?? false) {
            foreach ($primarySearch['results'] ?? [] as $r) {
                $id = ($r['document'] ?? $r['memory'])->id;
                $primaryResults[] = $r;
                $seenIds[$id]     = true;
            }
        }

        // ── Supplement: flat search always runs when mode is associative ──────
        // Adds results the associative traversal may have missed.
        // When mode is already flat, skip to avoid redundant search
        // (flat results are collected per-query in the main loop instead).
        $supplementResults = [];

        if ($mode === VectorMemoryFactoryInterface::MODE_ASSOCIATIVE) {
            $flatService = $this->vectorMemoryFactory->make(VectorMemoryFactoryInterface::MODE_FLAT, $engine);
            $flatSearch  = $flatService->searchVectorMemories($preset, $query, [
                'search_limit' => $searchLimit,
                'boost_recent' => true,
            ]);

            if ($flatSearch['success'] ?? false) {
                foreach ($flatSearch['results'] ?? [] as $r) {
                    $id = ($r['document'] ?? $r['memory'])->id;
                    if (!isset($seenIds[$id])) {
                        $supplementResults[] = $r;
                        $seenIds[$id]        = true;
                    }
                }
            }
        }

        // ── Separate embedding supplement (TF-IDF fallback inside embedding service) ─
        $embeddingFallback = [];
        $cleanPrimary      = [];

        foreach ($primaryResults as $r) {
            if (($r['source'] ?? '') === 'tfidf_fallback') {
                $embeddingFallback[] = $r;
            } else {
                $cleanPrimary[] = $r;
            }
        }

        $allSupplement = array_merge($supplementResults, $embeddingFallback);

        return [$cleanPrimary, $allSupplement];
    }

    // ── Query formulation ─────────────────────────────────────────────────────

    /**
     * Resolve the list of search queries for this enrichment cycle.
     *
     * Priority:
     *   1. Agent-provided queries via RagQueryPlugin (collected from metadata, then cleared).
     *   2. RAG preset model response, split on common separators into multiple queries.
     *
     * Always returns a flat array of non-empty strings, or null when nothing
     * could be formulated.
     *
     * @return string[]|null
     */
    protected function formulateQueries(AiPreset $ragPreset, AiPreset $mainPreset, array $context): ?array
    {
        // ── 1. Agent-provided queries ─────────────────────────────────────────
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

        // ── 2. RAG preset model formulation ───────────────────────────────────
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

            $raw     = trim(strip_tags($response->getResponse()));
            $queries = $this->splitQueryResponse($raw);
            $queries = $this->sanitizeQueries($queries);

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
     * Tries known separators in priority order; falls back to a single-item array.
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
     * Sanitize an array of raw query strings:
     * - Strips surrounding quote characters (straight, typographic)
     * - Normalises internal whitespace
     * - Truncates to 200 characters
     * - Removes empty entries
     *
     * @param  string[] $queries
     * @return string[]
     */
    private function sanitizeQueries(array $queries): array
    {
        $result = [];

        foreach ($queries as $q) {
            $q = trim($q);
            // Strip surrounding quote characters (straight, typographic, guillemets)
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
     * Format all sources into a single RAG context block for the system prompt.
     *
     * @param string[]  $queries        All queries used this cycle (for the header).
     * @param array     $primaryResults Associative / main vector results.
     * @param array     $supplementResultsOnce Flat dedup complement from primary query.
     * @param array     $flatResults    Flat results collected across all queries.
     * @param array     $skillResults   Skill search results.
     * @param array     $journalResults Journal entries collected across all queries.
     */
    protected function formatResults(
        AiPreset $preset,
        array  $queries,
        string $mode,
        string $engine,
        array  $primaryResults,
        array  $supplementResultsOnce,
        array  $flatResults,
        array  $skillResults,
        array  $journalResults = [],
        int    $maxContentLimit = 400,
    ): string {
        // Header — show all queries when there are several
        $queryHeader = count($queries) === 1
            ? "query: \"{$queries[0]}\""
            : 'queries: ' . implode(' | ', array_map(fn ($q) => "\"{$q}\"", $queries));

        $lines = ["[RAG CONTEXT — {$queryHeader}]", ''];

        // Primary memory results — label depends on active mode
        if (!empty($primaryResults)) {
            $label   = $this->primarySectionLabel($mode, $engine);
            $lines[] = $label;

            foreach ($primaryResults as $i => $result) {
                $lines[] = $this->formatMemoryLine($i + 1, $result, $maxContentLimit, composite: true);
            }

            $lines[] = '';
        }

        // Supplement from primary query (associative complement / tfidf fallback)
        if (!empty($supplementResultsOnce)) {
            $lines[] = '[KEYWORD MEMORY — no embedding yet]';

            foreach ($supplementResultsOnce as $i => $result) {
                $lines[] = $this->formatMemoryLine($i + 1, $result, $maxContentLimit, composite: false);
            }

            $lines[] = '';
        }

        // Flat results collected across all queries
        if (!empty($flatResults)) {
            $lines[] = count($queries) > 1
                ? '[MULTI-QUERY MEMORY]'
                : '[ADDITIONAL MEMORY]';

            foreach ($flatResults as $i => $result) {
                $lines[] = $this->formatMemoryLine($i + 1, $result, $maxContentLimit, composite: false);
            }

            $lines[] = '';
        }

        // Skills
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

        // Journal
        if (!empty($journalResults)) {
            $lines[] = '[RELEVANT JOURNAL ENTRIES]';

            $anchorIds  = array_map(fn ($e) => $e->id, $journalResults);
            $neighbours = $this->journalContextWindow > 0
                ? $this->journalService->fetchNeighbours($preset, $anchorIds, $this->journalContextWindow)
                : [];

            $timeline = [];
            foreach ($journalResults as $entry) {
                $timeline[$entry->id] = ['entry' => $entry, 'is_anchor' => true];
            }
            foreach ($neighbours as $id => $entry) {
                $timeline[$id] = ['entry' => $entry, 'is_anchor' => false];
            }

            uasort(
                $timeline,
                fn ($a, $b) => $a['entry']->recorded_at <=> $b['entry']->recorded_at
            );

            foreach ($timeline as ['entry' => $entry, 'is_anchor' => $isAnchor]) {
                $date = $entry->recorded_at->format('Y-m-d H:i');
                if ($this->showRelativeDate) {
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

        $lines[] = '[END RAG CONTEXT]';

        return implode("\n", $lines);
    }

    /**
     * Format a single memory result line.
     *
     * @param bool $composite Use composite_score when true, similarity otherwise.
     */
    private function formatMemoryLine(int $num, array $result, int $maxContentLimit, bool $composite): string
    {
        $memory    = $result['document'] ?? $result['memory'];
        $score     = round((($composite ? ($result['composite_score'] ?? null) : null) ?? $result['similarity']) * 100, 1);
        $content   = mb_substr($memory->getTextContent(), 0, $maxContentLimit);
        $createdAt = $memory->getCreatedAt();
        $dateStr   = $createdAt->format('Y-m-d');

        if ($this->showRelativeDate) {
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
        $now  = now();
        $diff = abs($now->diffInSeconds($date, false));

        return match (true) {
            $diff < 60         => 'just now',
            $diff < 3600       => (int) ($diff / 60) . 'm ago',
            $diff < 86400      => (int) ($diff / 3600) . 'h ago',
            $diff < 86400 * 7  => (int) ($diff / 86400) . 'd ago',
            $diff < 86400 * 30 => (int) ($diff / (86400 * 7)) . 'w ago',
            default            => (int) ($diff / (86400 * 30)) . 'mo ago',
        };
    }

    /**
     * Create system message
     */
    protected function createMessage(string $content, int $presetId, string $role, array $metadata = []): Message
    {
        return $this->messageModel->create([
            'role'               => 'system',
            'content'            => $content,
            'from_user_id'       => null,
            'preset_id'          => $presetId,
            'is_visible_to_user' => true,
            'metadata'           => $metadata,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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
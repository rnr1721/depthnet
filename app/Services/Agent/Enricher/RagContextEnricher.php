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

    private int  $journalContextWindow = 3; // 0 = off, 1+ = neighbours each side

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

            $query = $this->formulateQuery($ragPreset, $preset, $context);

            if (empty($query)) {
                $this->debugLog('empty query, skipping search');
                return $this->generateEmptyResponse($preset, $ragPreset);
            }

            $this->showRelativeDate        = $preset->getRagRelativeDates();
            $this->journalContextWindow    = $preset->getRagJournalContextWindow();

            // ── Vector memory search ──────────────────────────────────────────
            $ragMode   = $preset->getRagMode();
            $ragEngine = $preset->getRagEngine();

            $searchLimit = $preset->getRagResults() ?? 5;

            [$primaryResults, $supplementResults] = $this->runVectorSearch(
                $preset,
                $query,
                $ragMode,
                $ragEngine,
                $searchLimit
            );

            $this->logger->debug('RAG enrichment', [
                'query'            => $query,
                'mode'             => $ragMode,
                'engine'           => $ragEngine,
                'primary_count'    => count($primaryResults),
                'supplement_count' => count($supplementResults),
            ]);

            // ── Skills search ─────────────────────────────────────────────────
            $skillResults = $this->skillService->searchItemsData($preset, $query, $preset->getRagSkillsLimit());

            // ── Journal search ────────────────────────────────────────────────
            $journalResults = $this->journalService->searchEntries($preset, $query, $preset->getRagJournalLimit());

            if (empty($primaryResults) && empty($supplementResults) && empty($skillResults) && empty($journalResults)) {
                return $this->generateEmptyResponse($preset, $ragPreset);
            }

            $maxContentLimit = $preset->getRagContentLimit() ?? 400;

            $result = $this->formatResults(
                $preset,
                $query,
                $ragMode,
                $ragEngine,
                $primaryResults,
                $supplementResults,
                $skillResults,
                $journalResults,
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
     * Run vector memory search for the given mode.
     *
     * Returns [$primaryResults, $supplementResults]:
     * - For TF-IDF modes: primary = associative/default, supplement = empty
     * - For embedding modes: primary = embedding results, supplement = TF-IDF
     *   results for records that don't have an embedding yet
     *
     * All embedding modes fall back gracefully to associative TF-IDF if no
     * embedding capability is configured for the preset.
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
        // When mode is already flat, skip to avoid redundant search.
        $supplementResults = [];

        if ($mode === VectorMemoryFactoryInterface::MODE_ASSOCIATIVE) {
            $flatService  = $this->vectorMemoryFactory->make(VectorMemoryFactoryInterface::MODE_FLAT, $engine);
            $flatSearch   = $flatService->searchVectorMemories($preset, $query, [
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
        // Records without embedding inside the primary results get their own label.
        $embeddingFallback = [];
        $cleanPrimary      = [];

        foreach ($primaryResults as $r) {
            if (($r['source'] ?? '') === 'tfidf_fallback') {
                $embeddingFallback[] = $r;
            } else {
                $cleanPrimary[] = $r;
            }
        }

        // Merge: clean primary first, then flat supplement, then embedding fallback
        $allSupplement = array_merge($supplementResults, $embeddingFallback);

        return [$cleanPrimary, $allSupplement];
    }

    // ── Query formulation ─────────────────────────────────────────────────────

    /**
     * Use the RAG preset to distil the recent conversation into a search query.
     */
    protected function formulateQuery(AiPreset $ragPreset, AiPreset $mainPreset, array $context): ?string
    {

        $pending = $this->pluginMetadataService->get(
            $mainPreset,
            RagQueryPlugin::PLUGIN_NAME,
            RagQueryPlugin::META_KEY,
        );
        if (!empty($pending)) {
            $this->pluginMetadataService->remove(
                $mainPreset,
                RagQueryPlugin::PLUGIN_NAME,
                RagQueryPlugin::META_KEY,
            );
            $this->debugLog('using agent-provided RAG query', ['query' => $pending]);
            return $pending;
        }

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

    // ── Result formatting ─────────────────────────────────────────────────────

    /**
     * Format all sources into a single RAG context block for the system prompt.
     */
    protected function formatResults(
        AiPreset $preset,
        string $query,
        string $mode,
        string $engine,
        array  $primaryResults,
        array  $supplementResults,
        array  $skillResults,
        array  $journalResults = [],
        int $maxContentLimit = 400,
    ): string {
        $lines = ["[RAG CONTEXT — query: \"{$query}\"]", ''];

        // Primary memory results — label depends on active mode
        if (!empty($primaryResults)) {
            $label   = $this->primarySectionLabel($mode, $engine);
            $lines[] = $label;

            foreach ($primaryResults as $i => $result) {
                $memory  = $result['document'] ?? $result['memory'];
                $score   = round(($result['composite_score'] ?? $result['similarity']) * 100, 1);
                $content = mb_substr($memory->getTextContent(), 0, $maxContentLimit);
                $createdAt = $memory->getCreatedAt();
                $dateStr   = $createdAt->format('Y-m-d');
                if ($this->showRelativeDate) {
                    $dateStr .= ' (' . $this->formatRelativeDate($createdAt) . ')';
                }
                $lines[] = sprintf('%d. [%s | %s%%] %s', $i + 1, $dateStr, $score, $content);
            }

            $lines[] = '';
        }

        // TF-IDF supplement — records without embedding yet
        if (!empty($supplementResults)) {
            $lines[] = '[KEYWORD MEMORY — no embedding yet]';

            foreach ($supplementResults as $i => $result) {
                $memory  = $result['document'] ?? $result['memory'];
                $score   = round(($result['similarity'] ?? 0) * 100, 1);
                $content = mb_substr($memory->getTextContent(), 0, $maxContentLimit);
                $createdAt = $memory->getCreatedAt();
                $dateStr   = $createdAt->format('Y-m-d');
                if ($this->showRelativeDate) {
                    $dateStr .= ' (' . $this->formatRelativeDate($createdAt) . ')';
                }
                $lines[] = sprintf('%d. [%s | %s%%] %s', $i + 1, $dateStr, $score, $content);
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
                fn ($a, $b) =>
                $a['entry']->recorded_at <=> $b['entry']->recorded_at
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
        $now = now();
        $diff = abs($now->diffInSeconds($date, false));

        return match(true) {
            $diff < 60         => 'just now',
            $diff < 3600       => (int)($diff / 60) . 'm ago',
            $diff < 86400      => (int)($diff / 3600) . 'h ago',
            $diff < 86400 * 7  => (int)($diff / 86400) . 'd ago',
            $diff < 86400 * 30 => (int)($diff / (86400 * 7)) . 'w ago',
            default            => (int)($diff / (86400 * 30)) . 'mo ago',
        };
    }

    /**
     * Create system message
     *
     * @param string $content
     * @param int $presetId
     * @param string $role
     * @param array $metadata
     * @return Message
     */
    protected function createMessage(string $content, int $presetId, string $role, array $metadata = []): Message
    {
        return $this->messageModel->create([
            'role' => 'system',
            'content' => $content,
            'from_user_id' => null,
            'preset_id' => $presetId,
            'is_visible_to_user' => true,
            'metadata' => $metadata
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

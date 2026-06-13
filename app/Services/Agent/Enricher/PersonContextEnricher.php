<?php

namespace App\Services\Agent\Enricher;

use App\Contracts\Agent\Enricher\EnricherResponseInterface;
use App\Contracts\Agent\Enricher\PersonContextEnricherInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionType;
use App\Contracts\Agent\Memory\PersonMemoryServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Models\AiPreset;
use App\Models\PersonMemory;
use App\Services\Agent\Enricher\Rag\RagItem;
use App\Services\Agent\Enricher\Rag\RagSection;
use Psr\Log\LoggerInterface;

/**
 * PersonContextEnricher
 *
 * Provides person facts relevant to the current conversation in two forms:
 *
 *   - enrich()          : legacy text block, used standalone
 *                          (returns EnricherResponse with [PERSONS CONTEXT] block)
 *   - enrichAsSection() : structured RagSection of type 'persons',
 *                          for integration into the unified RAG output
 *
 * Both share the same retrieval strategy:
 *
 *  1. Heart-aware: if HeartPlugin is active and has connections/focus,
 *     fetch facts for those people directly (deterministic, no search).
 *  2. Query-based: use the last N messages as a semantic query,
 *     find relevant facts via embedding/TF-IDF search.
 *  3. Empty: if no people stored, returns empty response.
 */
class PersonContextEnricher implements PersonContextEnricherInterface
{
    /** HeartPlugin stores its state under this plugin name */
    private const HEART_PLUGIN = 'heart';

    /** How many recent messages to use as search query */
    private const CONTEXT_MESSAGES = 6;

    /** Max chars per message when building query string */
    private const MESSAGE_EXCERPT = 300;

    public function __construct(
        protected PersonMemoryServiceInterface   $personMemoryService,
        protected PluginMetadataServiceInterface $pluginMetadata,
        protected LoggerInterface                $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Public API — legacy text mode
    // -------------------------------------------------------------------------

    /**
     * Build the persons context block as a text response (legacy).
     */
    public function enrich(AiPreset $preset, array $context, ?string $target = null): EnricherResponseInterface
    {
        try {
            $grouped = $this->retrieveGroupedFacts($preset, $context);

            if (empty($grouped)) {
                return $this->generateEmptyResponse($preset);
            }

            return new EnricherResponse($preset, null, $this->formatLegacyText($grouped));

        } catch (\Throwable $e) {
            $this->logger->warning('PersonContextEnricher::enrich error', [
                'preset_id' => $preset->getId(),
                'error'     => $e->getMessage(),
            ]);
            return $this->generateEmptyResponse($preset);
        }
    }

    // -------------------------------------------------------------------------
    // Public API — structured section mode
    // -------------------------------------------------------------------------

    /**
     * Build a RagSection of type 'persons' for use in the unified RAG output.
     *
     * Returns null when no relevant persons facts were found.
     */
    public function enrichAsSection(AiPreset $preset, array $context): ?RagSectionInterface
    {
        try {
            $grouped = $this->retrieveGroupedFacts($preset, $context);

            if (empty($grouped)) {
                return null;
            }

            $items = $this->buildItems($grouped);

            if (empty($items)) {
                return null;
            }

            return new RagSection(
                type:  RagSectionType::Persons->value,
                items: $items,
            );

        } catch (\Throwable $e) {
            $this->logger->warning('PersonContextEnricher::enrichAsSection error', [
                'preset_id' => $preset->getId(),
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Shared retrieval pipeline
    // -------------------------------------------------------------------------

    /**
     * @return array<string, PersonMemory[]>  keyed by person_name, in retrieval order
     */
    private function retrieveGroupedFacts(AiPreset $preset, array $context): array
    {
        if (!$this->hasPeople($preset)) {
            return [];
        }

        // Strategy 1 — Heart focus
        $heartNames = $this->getHeartNames($preset);
        if (!empty($heartNames)) {
            $grouped = $this->personMemoryService->getFactsForNames($preset, $heartNames);
            if (!empty($grouped)) {
                return $grouped;
            }
        }

        // Strategy 2 — semantic search from recent context
        $query = $this->buildQuery($context);
        if (empty($query)) {
            return [];
        }

        $grouped = $this->personMemoryService->getRelevantFacts($preset, $query);
        return $grouped ?: [];
    }

    /**
     * Convert grouped facts into flat RagItem list,
     * with person_name in metadata for the renderer to group by.
     *
     * @param  array<string, PersonMemory[]> $grouped
     * @return RagItem[]
     */
    private function buildItems(array $grouped): array
    {
        $items = [];

        foreach ($grouped as $personName => $facts) {
            if (empty(trim($personName))) {
                continue;
            }

            foreach ($facts as $fact) {
                $items[] = new RagItem(
                    dedupKey: 'person_fact:' . $fact->id,
                    content:  $fact->content,
                    score:    null,
                    metadata: [
                        'person_name' => $personName,
                        'fact_id'     => $fact->id,
                    ],
                );
            }
        }

        return $items;
    }

    // -------------------------------------------------------------------------
    // Heart integration
    // -------------------------------------------------------------------------

    /**
     * Extract person names from HeartPlugin state.
     * Returns names from connections + dominant focus if set.
     *
     * @return string[]
     */
    private function getHeartNames(AiPreset $preset): array
    {
        try {
            $raw = $this->pluginMetadata->get($preset, self::HEART_PLUGIN, 'state', null);

            if ($raw === null) {
                return [];
            }

            $state = is_string($raw) ? json_decode($raw, true) : (array) $raw;

            if (!is_array($state)) {
                return [];
            }

            $names = [];

            // Dominant focus first — highest priority
            if (!empty($state['dominant'])) {
                $names[] = $state['dominant'];
            }

            // All connections
            foreach (array_keys($state['connections'] ?? []) as $name) {
                if (!in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }

            return $names;

        } catch (\Throwable) {
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Query builder
    // -------------------------------------------------------------------------

    /**
     * Build a search query from the tail of the conversation context.
     */
    private function buildQuery(array $context): string
    {
        $relevant = array_filter(
            $context,
            fn ($m) => in_array($m['role'] ?? '', ['user', 'assistant'], true)
        );

        $tail = array_slice(array_values($relevant), -self::CONTEXT_MESSAGES);

        if (empty($tail)) {
            return '';
        }

        return implode(' ', array_map(
            fn ($m) => mb_substr($m['content'] ?? '', 0, self::MESSAGE_EXCERPT),
            $tail
        ));
    }

    // -------------------------------------------------------------------------
    // Fast existence check
    // -------------------------------------------------------------------------

    private function hasPeople(AiPreset $preset): bool
    {
        return PersonMemory::forPreset($preset->getId())->exists();
    }

    // -------------------------------------------------------------------------
    // Legacy text formatting
    // -------------------------------------------------------------------------

    /**
     * @param array<string, PersonMemory[]> $grouped  keyed by person_name
     */
    private function formatLegacyText(array $grouped): string
    {
        $lines = ['[PERSONS CONTEXT]', ''];
        $hasContent = false;

        foreach ($grouped as $personName => $facts) {
            if (empty(trim($personName))) {
                continue;
            }
            $hasContent = true;
            $lines[] = "{$personName}:";
            foreach ($facts as $fact) {
                $lines[] = "  #{$fact->id} {$fact->content}";
            }
            $lines[] = '';
        }

        if (!$hasContent) {
            return '';
        }

        $lines[] = '[END PERSONS CONTEXT]';

        return implode("\n", $lines);
    }

    private function generateEmptyResponse(AiPreset $mainPreset): EnricherResponseInterface
    {
        return new EnricherResponse($mainPreset);
    }
}

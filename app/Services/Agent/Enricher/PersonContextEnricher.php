<?php

namespace App\Services\Agent\Enricher;

use App\Contracts\Agent\Enricher\EnricherResponseInterface;
use App\Contracts\Agent\Enricher\PersonContextEnricherInterface;
use App\Contracts\Agent\Memory\PersonMemoryServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Models\AiPreset;
use App\Models\PersonMemory;
use Psr\Log\LoggerInterface;

/**
 * PersonContextEnricher
 *
 * Builds [[persons_context]] — a compact block of person facts
 * relevant to the current conversation context.
 *
 * Strategy (in priority order):
 *
 *  1. Heart-aware: if HeartPlugin is active and has connections/focus,
 *     fetch facts for those people directly (deterministic, no search).
 *
 *  2. Query-based: use the last N messages as a semantic query,
 *     find relevant facts via embedding/TF-IDF search.
 *
 *  3. Empty: if no people stored, returns empty string silently.
 *
 * Output example injected into [[persons_context]]:
 *
 *   [PERSONS CONTEXT]
 *
 *   Eugeny / Zhenya / James Kvakiani:
 *     #1 Developer of DepthNet
 *     #2 Lives in Kharkiv
 *     #3 Loves punk aesthetic and travel
 *
 *   [END PERSONS CONTEXT]
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
        protected PersonMemoryServiceInterface  $personMemoryService,
        protected PluginMetadataServiceInterface $pluginMetadata,
        protected LoggerInterface               $logger,
    ) {
    }

    /**
     * Build the persons context block for a preset.
     *
     * @param  array $context  Current conversation messages
     * @return EnricherResponseInterface Ready to inject into [[persons_context]]
     */
    public function enrich(AiPreset $preset, array $context, ?string $target = null): EnricherResponseInterface
    {
        try {
            // Fast-exit if no people stored at all
            if (!$this->hasPeople($preset)) {
                return $this->generateEmptyResponse($preset);
            }

            // Strategy 1 — Heart focus
            $heartNames = $this->getHeartNames($preset);
            if (!empty($heartNames)) {
                $grouped = $this->personMemoryService->getFactsForNames($preset, $heartNames);
                if (!empty($grouped)) {
                    return new EnricherResponse($preset, null, $this->format($grouped));
                }
            }

            // Strategy 2 — semantic search from recent context
            $query = $this->buildQuery($context);
            if (empty($query)) {
                return $this->generateEmptyResponse($preset);
            }

            $grouped = $this->personMemoryService->getRelevantFacts($preset, $query);
            if (empty($grouped)) {
                return $this->generateEmptyResponse($preset);
            }

            return new EnricherResponse($preset, null, $this->format($grouped));

        } catch (\Throwable $e) {
            $this->logger->warning('PersonContextEnricher::enrich error', [
                'preset_id' => $preset->getId(),
                'error'     => $e->getMessage(),
            ]);
            return $this->generateEmptyResponse($preset);
        }
    }

    // -------------------------------------------------------------------------
    // Heart integration
    // -------------------------------------------------------------------------

    /**
     * Extract person names from HeartPlugin state.
     * Returns names from connections + dominant focus if set.
     *
     * Heart stores state as JSON under pluginMetadata key 'state':
     * {
     *   "connections": {"Eugeny": {...}, "DepthNet": {...}},
     *   "dominant": "Eugeny"
     * }
     *
     * We only want entities that look like people — Heart tracks
     * concepts too. We return all connection names and let
     * getFactsForNames() silently skip those not in person memory.
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
     * Concatenates recent user + assistant messages into a plain string.
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
        return \App\Models\PersonMemory::forPreset($preset->getId())->exists();
    }

    // -------------------------------------------------------------------------
    // Formatting
    // -------------------------------------------------------------------------

    /**
     * @param array<string, PersonMemory[]> $grouped  keyed by person_name
     */
    private function format(array $grouped): string
    {
        if (empty($grouped)) {
            return '';
        }

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

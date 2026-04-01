<?php

namespace App\Contracts\Agent\Journal;

use App\Models\AiPreset;

interface JournalServiceInterface
{
    /**
     * Add a new journal entry.
     *
     * Content format: "type | summary" or "type | summary | details | outcome:value"
     * Type defaults to 'observation' if not provided or unrecognized.
     */
    public function addEntry(AiPreset $preset, string $content): array;

    /**
     * Get recent entries in chronological order (newest first).
     */
    public function recent(AiPreset $preset, int $limit = 10): array;

    /**
     * Show full details of a single entry by ID.
     */
    public function show(AiPreset $preset, int $id): array;

    /**
     * Search entries semantically, optionally filtered by date.
     *
     * Query formats:
     *   "semantic query"                     — semantic only
     *   "2024-03-15 | semantic query"        — specific date + semantic
     *   "yesterday | semantic query"         — relative date + semantic
     *   "2024-03-10:2024-03-15 | query"      — date range + semantic
     *   "today"                              — date only (no semantic filter)
     */
    public function search(AiPreset $preset, string $query, int $limit = 10): array;

    /**
     * Search and return raw JournalEntry objects — used by RAG enricher.
     *
     * @return \App\Models\JournalEntry[]
     */
    public function searchEntries(AiPreset $preset, string $query, int $limit = 3): array;

    /**
     * Fetch neighbouring entries around a set of entry IDs.
     * Returns entries with recorded_at strictly before/after each anchor,
     * deduplicated and not overlapping with the anchor IDs themselves.
     *
     * @param  int[]  $entryIds  Anchor entry IDs (the search results)
     * @param  int    $window    How many neighbours before and after each anchor
     * @return \App\Models\JournalEntry[]    Keyed by id, tagged with 'neighbour_of' => [anchor_ids]
     */
    public function fetchNeighbours(AiPreset $preset, array $entryIds, int $window = 1): array;

    /*
     * Delete a single entry by ID.
     */
    public function delete(AiPreset $preset, int $id): array;

    /**
     * Clear all journal entries for a preset.
     */
    public function clear(AiPreset $preset): array;
}

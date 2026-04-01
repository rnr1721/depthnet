<?php

namespace App\Contracts\Agent\Memory;

use App\Models\AiPreset;

/**
 * Interface for managing person-specific memory facts.
 *
 * person_name stores the full alias string: "Primary / Alias1 / Alias2"
 * Facts are individual records identified by system ID.
 */
interface PersonMemoryServiceInterface
{
    /**
     * Add a fact about a person.
     * Creates the person if they don't exist yet.
     * Resolves existing person by name/alias automatically.
     */
    public function addFact(AiPreset $preset, string $personName, string $content): array;

    /**
     * Recall all facts about a person by name/alias or by any fact ID.
     *
     * @param string $nameOrId  Person name, alias, or numeric fact ID
     */
    public function recallPerson(AiPreset $preset, string $nameOrId): array;

    /**
     * Find persons by mention — searches across all aliases in person_name.
     * Returns list of matching persons with IDs and fact counts.
     */
    public function findByMention(AiPreset $preset, string $term): array;

    /**
     * Semantic search across all facts for a preset.
     * Uses embedding cosine similarity, falls back to TF-IDF.
     */
    public function searchFacts(AiPreset $preset, string $query, int $limit = 5): array;

    /**
     * Add an alias to a person identified by any fact ID.
     * Updates person_name on ALL facts belonging to that person.
     */
    public function addAlias(AiPreset $preset, int $factId, string $alias): array;

    /**
     * Remove an alias from a person identified by any fact ID.
     * Cannot remove the primary name (first segment).
     */
    public function removeAlias(AiPreset $preset, int $factId, string $alias): array;

    /**
     * Delete a specific fact by its ID.
     */
    public function deleteFact(AiPreset $preset, int $factId): array;

    /**
     * Delete all facts about a person by name/alias or fact ID.
     *
     * @param string $nameOrId  Person name, alias, or numeric fact ID
     */
    public function forgetPerson(AiPreset $preset, string $nameOrId): array;

    /**
     * List all known people with IDs and fact counts.
     */
    public function listPeople(AiPreset $preset): array;

    /**
     * Get structured people data — used by UI and export.
     * Returns array grouped by person_name with their facts.
     */
    public function getStructuredPeople(AiPreset $preset): array;

    /**
     * Get relevant person facts for RAG/enricher context.
     * Semantic search across facts, grouped by person.
     *
     * @return array<string, \App\Models\PersonMemory[]>  keyed by person_name
     */
    public function getRelevantFacts(AiPreset $preset, string $query, int $limit = 5, int $factsPerPerson = 5): array;

    /**
     * Get facts for specific people by name — used by PersonContextEnricher
     * when Heart plugin provides the focus list.
     *
     * @param  string[] $names
     * @return array<string, \App\Models\PersonMemory[]>  keyed by person_name
     */
    public function getFactsForNames(AiPreset $preset, array $names, int $factsPerPerson = 5): array;

    /**
     * Delete all persons and facts for a preset.
     */
    public function clearAll(AiPreset $preset): void;
}

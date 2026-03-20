<?php

namespace App\Contracts\Agent\Memory;

use App\Models\AiPreset;

/**
 * Interface for managing person-specific memory facts.
 * Each person has their own numbered list of facts that can be
 * added, deleted, and recalled independently.
 */
interface PersonMemoryServiceInterface
{
    /**
     * Add a fact about a person
     *
     * @param AiPreset $preset
     * @param string $personName
     * @param string $content
     * @return array
     */
    public function addFact(AiPreset $preset, string $personName, string $content): array;

    /**
     * Get all facts about a person, formatted for display/context
     *
     * @param AiPreset $preset
     * @param string $personName
     * @return array
     */
    public function recallPerson(AiPreset $preset, string $personName): array;

    /**
     * Delete a specific fact about a person by position number
     *
     * @param AiPreset $preset
     * @param string $personName
     * @param integer $factNumber
     * @return array
     */
    public function deleteFact(AiPreset $preset, string $personName, int $factNumber): array;

    /**
     * List all people stored in memory
     *
     * @param AiPreset $preset
     * @return array
     */
    public function listPeople(AiPreset $preset): array;

    /**
     * Forget all facts about a person
     *
     * @param AiPreset $preset
     * @param string $personName
     * @return array
     */
    public function forgetPerson(AiPreset $preset, string $personName): array;

    /**
     * Get structured people
     *
     * @param AiPreset $preset
     * @return array
     */
    public function getStructuredPeople(AiPreset $preset): array;

    /**
     * Forget all persons per preset
     */
    public function clearAll(AiPreset $preset): void;
}

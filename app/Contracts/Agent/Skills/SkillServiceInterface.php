<?php

namespace App\Contracts\Agent\Skills;

use App\Models\AiPreset;

interface SkillServiceInterface
{
    /**
     * Create a new skill (with an optional first item).
     *
     * @param AiPreset    $preset
     * @param string      $title
     * @param string|null $description
     * @param string|null $firstItem   If provided, added as item #1 immediately
     * @return array{success: bool, message: string, skill_number: int|null}
     */
    public function addSkill(
        AiPreset $preset,
        string $title,
        ?string $description = null,
        ?string $firstItem = null
    ): array;

    /**
     * Add a new item to an existing skill.
     *
     * @param AiPreset $preset
     * @param int      $skillNumber  Skill's sequential number within preset
     * @param string   $content
     * @return array{success: bool, message: string}
     */
    public function addItem(AiPreset $preset, int $skillNumber, string $content): array;

    /**
     * Update an existing item's content and re-index its TF-IDF vector.
     *
     * @param AiPreset $preset
     * @param int      $skillNumber
     * @param int      $itemNumber
     * @param string   $content
     * @return array{success: bool, message: string}
     */
    public function updateItem(AiPreset $preset, int $skillNumber, int $itemNumber, string $content): array;

    /**
     * Delete a single item from a skill.
     *
     * @param AiPreset $preset
     * @param int      $skillNumber
     * @param int      $itemNumber
     * @return array{success: bool, message: string}
     */
    public function deleteItem(AiPreset $preset, int $skillNumber, int $itemNumber): array;

    /**
     * Delete an entire skill and all its items.
     *
     * @param AiPreset $preset
     * @param int      $skillNumber
     * @return array{success: bool, message: string}
     */
    public function deleteSkill(AiPreset $preset, int $skillNumber): array;

    /**
     * Show full skill content: title, description, and all items.
     *
     * @param AiPreset $preset
     * @param int      $skillNumber
     * @return array{success: bool, message: string}
     */
    public function showSkill(AiPreset $preset, int $skillNumber): array;

    /**
     * List all skills (title + description + item count) — no item content.
     *
     * @param AiPreset $preset
     * @return array{success: bool, message: string}
     */
    public function listSkills(AiPreset $preset): array;

    /**
     * Search skill items by semantic similarity using TF-IDF.
     *
     * @param AiPreset $preset
     * @param string   $query
     * @param int      $limit
     * @return array{success: bool, message: string}
     */
    public function searchItems(AiPreset $preset, string $query, int $limit = 5): array;

    /**
     * Return a compact skills summary for the context placeholder.
     * Only titles + descriptions — no item content.
     *
     * @param AiPreset $preset
     * @return string
     */
    public function getSkillsForContext(AiPreset $preset): string;
}

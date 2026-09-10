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
     * @param array|null  $tools       Optional array of tools associated with the skill
     * @return array{success: bool, message: string, skill_number: int|null}
     */
    public function addSkill(
        AiPreset $preset,
        string $title,
        ?string $description = null,
        ?string $firstItem = null,
        ?array $tools = null
    ): array;

    /**
     * Update a skill's own fields (title/description/tools) — NOT its items.
     * Only provided keys are changed. tools: pass an array to set, or omit to leave.
     *
     * @param AiPreset $preset
     * @param integer $skillNumber
     * @param array $fields
     * @return array
     */
    public function updateSkill(
        AiPreset $preset,
        int $skillNumber,
        array $fields
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
     * Return skills as structured array for the admin UI.
     *
     * @param AiPreset $preset
     * @return array
     */
    public function listSkillsData(AiPreset $preset): array;

    /**
     * Return skill with items as structured array for the admin UI.
     *
     * @param AiPreset $preset
     * @param integer $skillNumber
     * @return array
     */
    public function showSkillData(AiPreset $preset, int $skillNumber): array;

    /**
     * Return search results as structured array for the admin UI.
     *
     * @param AiPreset $preset
     * @param string $query
     * @param integer $limit
     * @return array
     */
    public function searchItemsData(AiPreset $preset, string $query, int $limit = 5): array;

    /**
     * Render the [SKILLS] inventory block for the system prompt.
     *
     * This is the model's map of what capabilities EXIST — including ones whose
     * tools are currently HIDDEN by lazy-loading. It must make the mechanic
     * unmistakable, or the model mistakes "a skill about terminals" for "the
     * terminal tool in hand" (observed: an agent read the old flat line
     * "#1 Code — ... (1 item)" and reported it could use code/terminal, though
     * those tools were correctly hidden from its schema).
     *
     * So each line states: number, title, LOADED/not-loaded status, the skill's
     * tools (filtered to live plugins), and — when not loaded and it HAS tools —
     * an explicit "load N to use" hint. A loaded skill shows its tools as active.
     * A toolless skill shows no tool line (pure knowledge; loading just injects it).
     *
     * @param  AiPreset $preset
     * @param  int[]    $loadedNumbers  Skill numbers currently loaded (from
     *                                  SkillLoadService — passed in so this stays
     *                                  pure data, no loading-plane dependency).
     * @param  string[] $livePluginNames Names of plugins that actually exist right
     *                                  now (from the registry) — tools not in this
     *                                  list are dropped from display so a renamed/
     *                                  deleted plugin never shows as a capability.
     *                                  Empty array = don't filter (show all tools).
     * @return string
     */
    public function getSkillsForContext(AiPreset $preset, array $loadedNumbers = [], array $livePluginNames = []): string;

    /**
     * Delete all skills associated with the given preset.
     *
     * All related SkillItem records are removed via database cascade.
     *
     * @param AiPreset $preset
     * @return array{success: bool, message: string}
     */
    public function deleteAllSkills(AiPreset $preset): array;
}

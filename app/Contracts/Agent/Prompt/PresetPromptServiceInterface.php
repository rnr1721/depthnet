<?php

namespace App\Contracts\Agent\Prompt;

use App\Models\AiPreset;
use App\Models\PresetPrompt;
use App\Models\PresetPromptVersion;
use Illuminate\Support\Collection;

/**
 * Service interface for managing prompts within a preset.
 *
 * Business rules:
 *  - Every preset must always have at least one prompt.
 *  - Deleting the last prompt is forbidden.
 *  - Deleting the active prompt automatically promotes the first remaining one.
 *  - Codes are unique per preset (enforced at DB level too).
 *
 * Versioning:
 *  - Content-changing writes append immutable snapshots (PresetPromptVersion).
 *  - Actor (edited_by / editor_user_id) is passed by the caller: 'agent' from
 *    the plugin, 'human' + user id from the controller, 'system' by default
 *    for seeding and automation.
 */
interface PresetPromptServiceInterface
{
    /**
     * All prompts for a preset, ordered by creation date.
     *
     * @param AiPreset $preset
     * @return Collection
     */
    public function getAll(AiPreset $preset): Collection;

    /**
     * Find a prompt by ID, scoped to the preset (prevents cross-preset access).
     *
     * @param AiPreset $preset
     * @param integer $promptId
     * @return PresetPrompt|null
     */
    public function findById(AiPreset $preset, int $promptId): ?PresetPrompt;

    /**
     * Find a prompt by code within a preset.
     *
     * @param AiPreset $preset
     * @param string $code
     * @return PresetPrompt|null
     */
    public function findByCode(AiPreset $preset, string $code): ?PresetPrompt;

    /**
     * Return the currently active prompt for a preset.
     * Falls back to the first available if active_prompt_id is NULL.
     *
     * @param AiPreset $preset
     * @return PresetPrompt|null
     */
    public function getActive(AiPreset $preset): ?PresetPrompt;

    /**
     * Version history for a prompt, newest first.
     *
     * @param AiPreset $preset
     * @param int $promptId
     * @return Collection<int, PresetPromptVersion>
     * @throws \RuntimeException if prompt doesn't belong to the preset
     */
    public function getHistory(AiPreset $preset, int $promptId): Collection;

    /**
     * Fetch a single version of a prompt by its version number.
     *
     * @param AiPreset $preset
     * @param int $promptId
     * @param int $version
     * @return PresetPromptVersion|null
     * @throws \RuntimeException if prompt doesn't belong to the preset
     */
    public function getVersion(AiPreset $preset, int $promptId, int $version): ?PresetPromptVersion;

    /**
     * Create a new prompt and optionally set it as active.
     * Writes v1 (the original state) to version history.
     *
     * @param AiPreset $preset
     * @param array    $data          Keys: code, content, description (optional)
     * @param bool     $setAsActive   Set this prompt as active after creation
     * @param string   $editedBy      Actor class: 'agent' | 'human' | 'system'
     * @param int|null $editorUserId  Concrete user id when edited_by = 'human'
     */
    public function create(
        AiPreset $preset,
        array $data,
        bool $setAsActive = false,
        string $editedBy = PresetPromptVersion::BY_SYSTEM,
        ?int $editorUserId = null
    ): PresetPrompt;

    /**
     * Update a prompt's content and/or metadata.
     * Code changes are allowed as long as the new code is unique within the preset.
     * A version snapshot is written ONLY when content actually changes.
     *
     * @param AiPreset $preset
     * @param int      $promptId
     * @param array    $data          May include: code, content, description, edit_summary
     * @param string   $editedBy      Actor class: 'agent' | 'human' | 'system'
     * @param int|null $editorUserId  Concrete user id when edited_by = 'human'
     * @return PresetPrompt
     */
    public function update(
        AiPreset $preset,
        int $promptId,
        array $data,
        string $editedBy = PresetPromptVersion::BY_SYSTEM,
        ?int $editorUserId = null
    ): PresetPrompt;

    /**
     * Revert a prompt's content to a previous version.
     *
     * Non-destructive: restores the target version's content onto the head and
     * appends a NEW version recording the revert. History is never rewritten.
     *
     * @param AiPreset $preset
     * @param int      $promptId
     * @param int      $targetVersion
     * @param string   $editedBy      Actor class: 'agent' | 'human' | 'system'
     * @param int|null $editorUserId  Concrete user id when edited_by = 'human'
     * @return PresetPrompt
     * @throws \RuntimeException if version not found or content is unchanged
     */
    public function revertToVersion(
        AiPreset $preset,
        int $promptId,
        int $targetVersion,
        string $editedBy = PresetPromptVersion::BY_SYSTEM,
        ?int $editorUserId = null
    ): PresetPrompt;

    /**
     * Delete a prompt.
     *
     * Rules:
     *  - Cannot delete the last prompt.
     *  - If deleting the active prompt, the first remaining one becomes active.
     *  - Version history cascade-deletes with the prompt.
     *
     * @param AiPreset $preset
     * @param integer $promptId
     * @return void
     * @throws \RuntimeException
     */
    public function delete(AiPreset $preset, int $promptId): void;

    /**
     * Set a specific prompt as active by its ID.
     *
     * @param AiPreset $preset
     * @param integer $promptId
     * @return void
     * @throws \RuntimeException if prompt doesn't belong to the preset
     */
    public function setActive(AiPreset $preset, int $promptId): void;

    /**
     * Set a specific prompt as active by its code.
     * This is the method used by the agent's switch-prompt command.
     *
     * @param AiPreset $preset
     * @param string $code
     * @return PresetPrompt
     * @throws \RuntimeException if code not found in preset
     */
    public function setActiveByCode(AiPreset $preset, string $code): PresetPrompt;

    /**
     * Duplicate a prompt within the same preset.
     * The duplicate gets a new auto-generated code (original_code_copy_N) and
     * its own fresh history starting at v1.
     *
     * @param AiPreset $preset
     * @param integer $promptId
     * @return PresetPrompt
     */
    public function duplicate(AiPreset $preset, int $promptId): PresetPrompt;
}

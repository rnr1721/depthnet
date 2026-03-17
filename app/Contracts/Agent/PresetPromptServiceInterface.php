<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;
use App\Models\PresetPrompt;
use Illuminate\Support\Collection;

/**
 * Service interface for managing prompts within a preset.
 *
 * Business rules:
 *  - Every preset must always have at least one prompt.
 *  - Deleting the last prompt is forbidden.
 *  - Deleting the active prompt automatically promotes the first remaining one.
 *  - Codes are unique per preset (enforced at DB level too).
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
     * Create a new prompt and optionally set it as active.
     *
     * @param AiPreset $preset
     * @param array    $data          Keys: code, content, description (optional)
     * @param bool     $setAsActive   Set this prompt as active after creation
     */
    public function create(AiPreset $preset, array $data, bool $setAsActive = false): PresetPrompt;

    /**
     * Update a prompt's content and/or metadata.
     * Code changes are allowed as long as the new code is unique within the preset.
     *
     * @param AiPreset $preset
     * @param integer $promptId
     * @param array $data
     * @return PresetPrompt
     */
    public function update(AiPreset $preset, int $promptId, array $data): PresetPrompt;

    /**
     * Delete a prompt.
     *
     * Rules:
     *  - Cannot delete the last prompt.
     *  - If deleting the active prompt, the first remaining one becomes active.
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
     * The duplicate gets a new auto-generated code (original_code_copy_N).
     *
     * @param AiPreset $preset
     * @param integer $promptId
     * @return PresetPrompt
     */
    public function duplicate(AiPreset $preset, int $promptId): PresetPrompt;

}

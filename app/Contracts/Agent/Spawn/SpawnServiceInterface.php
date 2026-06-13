<?php

namespace App\Contracts\Agent\Spawn;

use App\Models\AiPreset;
use Illuminate\Support\Collection;

/**
 * Manages ephemeral "spawned" presets created by an agent at runtime.
 *
 * A spawned preset is a lightweight clone of a parent preset with a custom
 * system prompt. It has no identity, memory, or personality plugins — it is
 * a pure instrument. The parent preset owns all its spawns; deleting the
 * parent cascades to all children via DB constraint.
 *
 * Preset codes for spawned presets follow the pattern:
 *   spawn_{parentId}_{slug}   e.g. spawn_3_json_parser
 * and must match ^[a-z][a-z0-9_]{1,48}$ after assembly.
 */
interface SpawnServiceInterface
{
    /**
     * Create a new spawned preset owned by $parentPresetId.
     *
     * @param  int         $parentPresetId  ID of the owning agent preset.
     * @param  string      $slug            Short identifier chosen by the agent
     *                                      (lowercase letters, digits, underscores).
     *                                      Used to build the preset_code.
     * @param  string      $systemPrompt    System prompt for the spawned preset.
     * @param  array       $overrides       Optional field overrides (engine_name,
     *                                      engine_config, max_context_limit, …).
     * @return AiPreset                     The newly created spawned preset.
     *
     * @throws \InvalidArgumentException    If $slug is invalid.
     * @throws \App\Exceptions\PresetException  If creation fails.
     */
    public function spawn(
        int $parentPresetId,
        string $slug,
        string $systemPrompt,
        array $overrides = []
    ): AiPreset;

    /**
     * Delete a spawned preset.
     *
     * Verifies that the preset actually belongs to $parentPresetId before
     * deleting — prevents an agent from killing presets it does not own.
     *
     * @param  int  $spawnedPresetId
     * @param  int  $parentPresetId
     * @return void
     *
     * @throws \App\Exceptions\PresetException  If preset not found or ownership mismatch.
     */
    public function kill(int $spawnedPresetId, int $parentPresetId): void;

    /**
     * Kill all spawned presets owned by $parentPresetId.
     * Useful for cleanup on parent preset reset or test teardown.
     *
     * @param  int $parentPresetId
     * @return int Number of presets deleted.
     */
    public function killAll(int $parentPresetId): int;

    /**
     * Reset a spawned preset: wipe all accumulated data (messages, memory,
     * vector memory, journal, etc.) and optionally replace its system prompt.
     *
     * Cheaper than kill + spawn when the agent wants to reuse the same
     * instrument slot without recreating it from scratch.
     *
     * @param  int         $spawnedPresetId
     * @param  int         $parentPresetId  Ownership check.
     * @param  string|null $newPrompt       If null — existing prompt is kept.
     * @return void
     *
     * @throws \App\Exceptions\PresetException If not found or ownership mismatch.
     */
    public function reset(int $spawnedPresetId, int $parentPresetId, ?string $newPrompt = null): void;

    /**
     * Return all live spawned presets for a given parent.
     *
     * @param  int        $parentPresetId
     * @return Collection<int, AiPreset>
     */
    public function listSpawns(int $parentPresetId): Collection;

    /**
     * Read the active system prompt of a spawned preset.
     *
     * @param  int     $spawnedPresetId
     * @param  int     $parentPresetId   Ownership check.
     * @return string
     *
     * @throws \App\Exceptions\PresetException  If not found or ownership mismatch.
     */
    public function readPrompt(int $spawnedPresetId, int $parentPresetId): string;

    /**
     * Replace the active system prompt of a spawned preset.
     *
     * @param  int     $spawnedPresetId
     * @param  int     $parentPresetId   Ownership check.
     * @param  string  $newPrompt
     * @return void
     *
     * @throws \App\Exceptions\PresetException  If not found or ownership mismatch.
     */
    public function updatePrompt(int $spawnedPresetId, int $parentPresetId, string $newPrompt): void;

    /**
     * Find a spawned preset by its preset_code, scoped to a parent.
     * Returns null if not found or not owned by the parent.
     *
     * @param  string  $presetCode
     * @param  int     $parentPresetId
     * @return AiPreset|null
     */
    public function findSpawnByCode(string $presetCode, int $parentPresetId): ?AiPreset;
}

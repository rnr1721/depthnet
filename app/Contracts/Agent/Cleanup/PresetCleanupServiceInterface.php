<?php

namespace App\Contracts\Agent\Cleanup;

use App\Models\Agent;
use App\Models\AiPreset;

interface PresetCleanupServiceInterface
{
    /**
     * Clear selected data for a single preset.
     *
     * Supported option keys (all boolean, default false except clear_messages):
     *   clear_messages, clear_memory, clear_vector_memory,
     *   clear_workspace, clear_goals, clear_skills,
     *   clear_person, clear_journal
     *
     * @param AiPreset $preset
     * @param array    $options
     * @return string[] List of what was actually cleared
     */
    public function clearPreset(AiPreset $preset, array $options): array;

    /**
     * Clear all presets belonging to an agent —
     * planner + every role preset (and validator presets).
     * Deduplicates presets so each is cleared only once.
     *
     * @param Agent $agent
     * @param array $options Same keys as clearPreset
     * @return array<string, string[]> preset_name => cleared items
     */
    public function clearAgent(Agent $agent, array $options): array;

    /**
     * Collect all unique preset IDs that belong to an agent
     * (planner + roles + validators).
     *
     * @param Agent $agent
     * @return int[]
     */
    public function getAgentPresetIds(Agent $agent): array;
}

<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;

/**
 * Resolves the active cognitive context profile (normal/extended) for a preset
 * and the context message limit it implies. Single source of truth for the
 * work-mode hysteresis state.
 */
interface ContextModeResolverInterface
{
    /**
     * Context message limit for the currently active profile.
     * Falls back to the preset's plain max_context_limit when the feature is
     * off or the agent is in normal mode.
     *
     * @param AiPreset $preset
     * @return integer
     */
    public function activeContextLimit(AiPreset $preset): int;

    /**
     * Whether the agent is currently in extended (work) mode.
     *
     * @param AiPreset $preset
     * @return boolean
     */
    public function isExtended(AiPreset $preset): bool;

    /**
     * Human-readable active mode label ('normal' | 'extended').
     *
     * @param AiPreset $preset
     * @return string
     */
    public function activeMode(AiPreset $preset): string;

    /**
     * Advance hysteresis state by one completed cycle. Called once per
     * successful cycle with the main cycle preset.
     *
     * @param AiPreset $preset
     * @param boolean $containedLongContextPlugin
     * @return void
     */
    public function updateStreak(AiPreset $preset, bool $containedLongContextPlugin): void;
}

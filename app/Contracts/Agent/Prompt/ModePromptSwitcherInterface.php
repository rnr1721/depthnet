<?php

namespace App\Contracts\Agent\Prompt;

use App\Models\AiPreset;

/**
 * ModePromptSwitcher — automatic, mode-driven prompt selection.
 *
 * The agent has one identity; the context mode narrows which prompt is active
 * without swapping who the agent is. A prompt tagged context_mode=extended is
 * activated automatically while the agent is in extended (work) mode; a
 * context_mode=normal prompt while in normal mode. Prompts tagged 'none' (the
 * default, and every pre-existing prompt) are outside the mechanism entirely.
 *
 * Ownership / conflict with the manual [mode] plugin (PromptPlugin):
 *   Both this and PromptPlugin change the active prompt (preset.active_prompt_id
 *   via PresetPromptService::setActive). We resolve it as "auto wins within a
 *   cycle" (strict variant): syncActivePromptForMode() runs each cycle and
 *   idempotently forces the active prompt to match the mode. So when any prompt
 *   of a preset is mode-tagged, a manual [mode] switch is effectively overridden
 *   on the next cycle. This is surfaced to the operator in the UI (a warning on
 *   the prompt's mode selector). When NO prompt is mode-tagged, this is inert and
 *   PromptPlugin owns the active prompt alone — exactly as today.
 *
 * Role ASSIGNMENT (which prompt holds which mode, and the one-per-mode
 * exclusivity) is NOT here — it lives where prompts are persisted
 * (PresetService::syncPrompts, phase 2). This service only READS context_mode to
 * drive activation at agent runtime. Two concerns, two places.
 */
interface ModePromptSwitcherInterface
{
    /**
     * Make the preset's active prompt match its current context mode.
     *
     * Idempotent: if the mode's prompt is already active, or the preset has no
     * prompt tagged for the current mode, nothing is written. Called once per
     * cycle, after the mode is resolved and before the prompt is assembled.
     *
     * @return bool True if the active prompt was changed this call.
     */
    public function syncActivePromptForMode(AiPreset $preset): bool;
}

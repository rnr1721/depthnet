<?php

namespace App\Services\Agent;

use App\Contracts\Agent\ContextModeResolverInterface;
use App\Contracts\Agent\PresetMetadataServiceInterface;
use App\Models\AiPreset;

/**
 * ContextModeResolver — single source of truth for "which context profile is
 * active right now" and "what context limit does it imply".
 *
 * Two cognitive profiles:
 *   normal   — short linear context + strong RAG. Good for subject-mode:
 *              conversation, reflection, associative recall.
 *   extended — long procedural context. Needed when the agent drives stateful
 *              plugins (browser, terminal, sandbox, code, shell, spawn,
 *              project map): the tool's real state lives outside the model, so
 *              the agent must remember its own steps or it acts blind.
 *
 * The profile is selected automatically by a hysteresis counter (work_streak):
 * cycles that invoke a long-context plugin push the streak up, others pull it
 * down. The agent enters extended mode only after sustained work (ENTER_THRESHOLD)
 * and leaves only when work fully stops (EXIT_THRESHOLD) — the dead band between
 * prevents flapping on a single stray browser call.
 *
 * Feature is OFF by default: when extended_limit is null, activeContextLimit()
 * always returns the plain max_context_limit and the whole mechanism is inert.
 * "Set max_context_limit and forget it" keeps working unchanged.
 *
 * State lives in preset metadata (not columns) under the context_mode namespace:
 *   context_mode.work_streak    int   runtime counter
 *   context_mode.active         bool  true = currently in extended mode
 *   context_mode.extended_limit int   extended profile's message limit (null = off)
 */
class ContextModeResolver implements ContextModeResolverInterface
{
    /** Enter extended mode once the streak reaches this. */
    private const ENTER_THRESHOLD = 3;

    /** Leave extended mode only when the streak falls back to this. */
    private const EXIT_THRESHOLD = 0;

    /** Cap so a long work session doesn't require dozens of decrements to exit. */
    private const STREAK_CAP = 5;

    private const KEY_STREAK   = 'context_mode.work_streak';
    private const KEY_ACTIVE   = 'context_mode.active';

    public function __construct(
        protected PresetMetadataServiceInterface $metadata,
    ) {
    }

    /**
     * The context message limit for the currently active profile.
     *
     * When the feature is off (extended_limit unset) or the agent is in normal
     * mode, returns the preset's plain max_context_limit. In extended mode,
     * returns the configured extended_limit.
     *
     * This is the ONE method all context-limit consumers should call instead
     * of reading getMaxContextLimit() directly.
     */
    public function activeContextLimit(AiPreset $preset): int
    {
        $base = $preset->getMaxContextLimit();

        $extendedLimit = $this->extendedLimit($preset);
        if ($extendedLimit === null) {
            return $base;          // feature off
        }

        return $this->isExtended($preset) ? $extendedLimit : $base;
    }

    /**
     * Whether the agent is currently in extended (work) mode.
     * Always false when the feature is off.
     */
    public function isExtended(AiPreset $preset): bool
    {
        if ($this->extendedLimit($preset) === null) {
            return false;
        }

        return (bool) $this->metadata->get($preset, self::KEY_ACTIVE, false);
    }

    /**
     * Human-readable active mode label, for the [[context_mode]] placeholder
     * shown to the agent so it has self-awareness of its current cognitive mode.
     */
    public function activeMode(AiPreset $preset): string
    {
        return $this->isExtended($preset) ? 'extended' : 'normal';
    }

    /**
     * Advance the hysteresis state by one completed cycle.
     *
     * Called exactly once per successful thinking cycle, by AgentActionsHandler,
     * with the main cycle preset. No-op when the feature is off.
     *
     * @param bool $containedLongContextPlugin Whether this cycle ran a stateful plugin.
     */
    public function updateStreak(AiPreset $preset, bool $containedLongContextPlugin): void
    {
        // Feature off — don't even spend a write.
        if ($this->extendedLimit($preset) === null) {
            return;
        }

        $streak = (int) $this->metadata->get($preset, self::KEY_STREAK, 0);

        if ($containedLongContextPlugin) {
            $streak = min($streak + 1, self::STREAK_CAP);
        } else {
            $streak = max($streak - 1, 0);
        }

        $active = (bool) $this->metadata->get($preset, self::KEY_ACTIVE, false);

        // Hysteresis: only the edges flip the mode; the dead band leaves it as-is.
        if (!$active && $streak >= self::ENTER_THRESHOLD) {
            $active = true;
        } elseif ($active && $streak <= self::EXIT_THRESHOLD) {
            $active = false;
        }

        // One write for both keys.
        $this->metadata->update($preset, [
            self::KEY_STREAK => $streak,
            self::KEY_ACTIVE => $active,
        ]);
    }

    /**
     * The configured extended-profile limit, or null when the feature is off.
     */
    private function extendedLimit(AiPreset $preset): ?int
    {
        $value = $preset->getMaxContextLimitExtended();

        // Treat 0 as "off" too — a zero-length extended context is meaningless
        // and almost certainly a misconfiguration; fall back to normal.
        return ($value === null || $value <= 0) ? null : $value;
    }
}

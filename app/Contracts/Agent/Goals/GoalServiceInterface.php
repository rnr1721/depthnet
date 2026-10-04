<?php

namespace App\Contracts\Agent\Goals;

use App\Models\AiPreset;
use App\Models\Goal;

/**
 * Interface for managing persistent goal tracking with progress history.
 *
 * Statuses: active, paused, done, dropped.
 *   active  — being pursued; listed in [[active_goals]]
 *   paused  — deferred ("not now"); hidden from [[active_goals]]
 *   done    — achieved
 *   dropped — abandoned on purpose ("this is not what I want after all")
 *
 * Focus (orthogonal to status): at most ONE goal per preset is "in focus".
 * Its full progress history is injected into the cycle context as desktop
 * material (ContextInjectionService), and it is listed first and marked in
 * [[active_goals]]. Any status other than active releases the focus
 * automatically; deleting the goal releases it too (the marker lives on the row).
 *
 * Goal numbers are display numbers: the 1-based index in the preset's ordered
 * goal list (all statuses). They are what the agent and the UI address goals by.
 */
interface GoalServiceInterface
{
    /**
     * Create a new goal.
     *
     * @return array{success: bool, message: string}
     */
    public function addGoal(AiPreset $preset, string $title, ?string $motivation): array;

    /**
     * Add a progress note to an existing goal.
     *
     * @return array{success: bool, message: string}
     */
    public function addProgress(AiPreset $preset, int $goalNumber, string $content): array;

    /**
     * Update the status of a goal. Any status other than 'active' also
     * releases the goal from focus.
     *
     * @param string $status One of: active, paused, done, dropped
     * @return array{success: bool, message: string}
     */
    public function setStatus(AiPreset $preset, int $goalNumber, string $status): array;

    /**
     * Put a goal in focus. Releases any previously focused goal of the preset.
     * A paused goal is resumed (set active) as part of focusing it.
     * done/dropped goals cannot be focused.
     *
     * @return array{success: bool, message: string}
     */
    public function focus(AiPreset $preset, int $goalNumber): array;

    /**
     * Release the focused goal without changing its status (it stays active).
     *
     * @return array{success: bool, message: string}
     */
    public function unfocus(AiPreset $preset): array;

    /**
     * The goal currently in focus, or null.
     */
    public function getFocusedGoal(AiPreset $preset): ?Goal;

    /**
     * Display number of the goal currently in focus, or null.
     */
    public function getFocusedGoalNumber(AiPreset $preset): ?int;

    /**
     * Pure data for rendering the focused goal as desktop material.
     *
     * @param int $historyLimit Max progress notes to return (newest kept); 0 = all
     * @return array{
     *     number: int, title: string, motivation: ?string,
     *     focused_at: \Illuminate\Support\Carbon,
     *     total_notes: int, omitted_notes: int,
     *     progress: array<int, array{content: string, created_at: \Illuminate\Support\Carbon}>,
     *     last_progress_at: ?\Illuminate\Support\Carbon
     * }|null  null when nothing is in focus
     */
    public function getFocusedGoalData(AiPreset $preset, int $historyLimit = 20): ?array;

    /**
     * Show full goal details including all progress notes with timestamps.
     *
     * @return array{success: bool, message: string}
     */
    public function showGoal(AiPreset $preset, int $goalNumber): array;

    /**
     * List goals filtered by status ('all' for everything). The focused goal is marked.
     *
     * @return array{success: bool, message: string}
     */
    public function listGoals(AiPreset $preset, string $status = 'active'): array;

    /**
     * Active goals for the [[active_goals]] placeholder. The focused goal comes
     * first and is marked; its last note is not repeated (it is on the desk).
     * Returns 'none' if no active goals exist.
     */
    public function getActiveGoalsForContext(AiPreset $preset): string;

    /**
     * Clear all goals for preset
     */
    public function clear(AiPreset $preset);
}

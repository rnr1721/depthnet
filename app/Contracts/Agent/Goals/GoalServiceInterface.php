<?php

namespace App\Contracts\Agent\Goals;

use App\Models\AiPreset;

/**
 * Interface for managing persistent goal tracking with progress history.
 *
 * Goals have three statuses: active, paused, done.
 * Active goals are injected into Dynamic Context via [[active_goals]] placeholder
 * so the agent always knows what it's working on and why.
 */
interface GoalServiceInterface
{
    /**
     * Create a new goal.
     *
     * @param AiPreset $preset
     * @param string $title What the goal is
     * @param string|null $motivation Why it matters — kept in context to prevent drift
     * @return array{success: bool, message: string}
     */
    public function addGoal(AiPreset $preset, string $title, ?string $motivation): array;

    /**
     * Add a progress note to an existing goal.
     * Notes are appended chronologically and shown in full via showGoal().
     * The latest note is also shown in the active goals context summary.
     *
     * @param AiPreset $preset
     * @param int $goalNumber Display number (position-based, 1-indexed)
     * @param string $content What was discovered or accomplished
     * @return array{success: bool, message: string}
     */
    public function addProgress(AiPreset $preset, int $goalNumber, string $content): array;

    /**
     * Update the status of a goal.
     *
     * @param AiPreset $preset
     * @param int $goalNumber Display number (position-based, 1-indexed)
     * @param string $status One of: active, paused, done
     * @return array{success: bool, message: string}
     */
    public function setStatus(AiPreset $preset, int $goalNumber, string $status): array;

    /**
     * Show full goal details including all progress notes with timestamps.
     *
     * @param AiPreset $preset
     * @param int $goalNumber Display number (position-based, 1-indexed)
     * @return array{success: bool, message: string}
     */
    public function showGoal(AiPreset $preset, int $goalNumber): array;

    /**
     * List goals filtered by status.
     * Each line shows: [N] [status] title | motivation (progress count)
     *
     * @param AiPreset $preset
     * @param string $status Filter by status. Use 'all' to return everything.
     * @return array{success: bool, message: string}
     */
    public function listGoals(AiPreset $preset, string $status = 'active'): array;

    /**
     * Get active goals formatted for the [[active_goals]] Dynamic Context placeholder.
     * Returns a compact summary: [N] title | motivation → last progress note
     * Returns 'none' if no active goals exist.
     *
     * @param AiPreset $preset
     * @return string
     */
    public function getActiveGoalsForContext(AiPreset $preset): string;
}

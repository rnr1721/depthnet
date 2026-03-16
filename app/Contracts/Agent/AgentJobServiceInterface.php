<?php

namespace App\Contracts\Agent;

/**
 * Interface for managing agent job lifecycle and execution control.
 *
 * Each preset runs its own independent thinking loop.
 * All methods that were previously global now accept a $presetId.
 * The old zero-argument signatures are kept as nullable/default overloads
 * only where strictly needed for backward compatibility.
 */
interface AgentJobServiceInterface
{
    /**
     * Check if a specific preset is currently active (loop enabled).
     *
     * @param int $presetId
     * @return bool
     */
    public function isActive(int $presetId): bool;

    /**
     * Determine if the thinking loop for a preset can be started.
     * Returns true when the preset is active and not currently locked.
     *
     * @param int $presetId
     * @return bool
     */
    public function canStart(int $presetId): bool;

    /**
     * Determine if the thinking loop for a preset can be stopped.
     * Returns true when a lock is held for the preset.
     *
     * @param int $presetId
     * @return bool
     */
    public function canStop(int $presetId): bool;

    /**
     * Dispatch the first thinking job for a preset.
     *
     * @param int $presetId
     * @param bool $singleMode - If single, not loop mode
     * @return bool
     */
    public function start(int $presetId, bool $singleMode = false): bool;

    /**
     * Release the lock for a preset, preventing the next cycle from starting.
     *
     * @param int $presetId
     * @return bool
     */
    public function stop(int $presetId): bool;

    /**
     * Check whether the thinking process for a preset is currently locked/running.
     *
     * @param int $presetId
     * @return bool
     */
    public function isLocked(int $presetId): bool;

    /**
     * Execute a single thinking cycle for the given preset.
     * Called by ProcessAgentThinking job — do not call directly.
     *
     * @param int $presetId
     * @return void
     */
    public function processThinkingCycle(int $presetId): void;

    /**
     * Update active/inactive status for a specific preset and manage its loop.
     *
     * @param int  $presetId
     * @param bool $isActive
     * @return bool
     */
    public function updateModelSettings(int $presetId, bool $isActive): bool;

    /**
     * Get current settings for a specific preset.
     *
     * @param int $presetId
     * @return array{preset_id: int, chat_active: bool, is_locked: bool, can_start: bool, can_stop: bool}
     */
    public function getModelSettings(int $presetId): array;

    /**
     * Get settings for ALL presets (for status overview / artisan command).
     *
     * @return array<int, array>
     */
    public function getAllModelSettings(): array;
}

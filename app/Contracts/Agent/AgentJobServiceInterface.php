<?php

namespace App\Contracts\Agent;

/**
 * Interface for managing agent job lifecycle and execution control.
 *
 * This service provides methods to control the agent's thinking process,
 * including starting, stopping, and monitoring the execution state.
 * The agent can operate in different modes (single/looped) and uses
 * locking mechanism to prevent concurrent executions.
 */
interface AgentJobServiceInterface
{
    /**
     * Check if the agent is currently active and enabled for processing.
     *
     * This method checks the global agent activation state, typically
     * controlled by the 'model_active' configuration option.
     *
     * @return bool True if the agent is active and can potentially run
     */
    public function isActive(): bool;

    /**
     * Determine if the agent thinking process can be started.
     *
     * Checks all preconditions for starting the agent:
     * - Agent must be active (isActive() returns true)
     * - Agent must be in 'looped' mode (not 'single')
     * - No active lock should be present (not currently running)
     *
     * @return bool True if all conditions are met to start the process
     */
    public function canStart(): bool;

    /**
     * Determine if the agent thinking process can be stopped.
     *
     * The agent can be stopped if it's currently running, which is
     * indicated by the presence of an active lock.
     *
     * @return bool True if the agent is currently running and can be stopped
     */
    public function canStop(): bool;

    /**
     * Start the agent thinking process.
     *
     * Initiates the agent's thinking cycle by dispatching the first job.
     * This method will only succeed if canStart() returns true.
     *
     * @return bool True if the process was successfully started, false otherwise
     */
    public function start(): bool;

    /**
     * Stop the agent thinking process.
     *
     * Stops the current thinking cycle by releasing the lock.
     * This prevents the next cycle from being scheduled.
     * Note: This doesn't immediately terminate a currently executing job,
     * but prevents new cycles from starting.
     *
     * @return bool True if the process was successfully stopped, false otherwise
     */
    public function stop(): bool;

    /**
     * Check if the agent thinking process is currently locked/running.
     *
     * The lock mechanism prevents multiple instances of the thinking
     * process from running simultaneously. When locked, it indicates
     * that a thinking cycle is currently in progress.
     *
     * @return bool True if the process is currently locked/running
     */
    public function isLocked(): bool;

    /**
     * Execute a single thinking cycle.
     *
     * This is the main method that handles the core logic of the agent's
     * thinking process. It:
     * - Checks preconditions (active state, mode, lock status)
     * - Acquires a lock to prevent concurrent execution
     * - Calls the agent's think() method
     * - Schedules the next cycle (if in looped mode)
     * - Handles errors and ensures proper cleanup
     *
     * This method is typically called by the ProcessAgentThinking job
     * and should not be called directly from application code.
     *
     * @return void
     * @throws \Throwable If the thinking process encounters an error
     */
    public function processThinkingCycle(): void;

    /**
     * Update model settings and manage agent state accordingly.
     *
     * Updates the default model and active state, then:
     * - Restarts the queue to apply new settings
     * - Starts the agent if it was activated
     * - Stops the agent if it was deactivated
     *
     * @param int $presetId The ID of the preset to set as default
     * @param bool $isActive Whether the agent should be active
     * @return bool True if settings were updated successfully
     */
    public function updateModelSettings(int $presetId, bool $isActive): bool;

    /**
     * Get current model settings.
     *
     * Returns the current model configuration including:
     * - Default model name
     * - Active state
     * - Current mode
     *
     * @return array Current model settings
     */
    public function getModelSettings(): array;
}

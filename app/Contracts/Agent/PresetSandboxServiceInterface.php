<?php

declare(strict_types=1);

namespace App\Contracts\Agent;

use App\Exceptions\PresetException;

/**
 * Interface for managing preset-sandbox relationships
 *
 * Defines contract for linking presets to sandboxes via metadata
 * with proper validation and cleanup functionality.
 */
interface PresetSandboxServiceInterface
{
    /**
     * Assign sandbox to preset
     *
     * @param int $presetId
     * @param string $sandboxId
     * @return bool
     * @throws PresetException
     */
    public function assignSandbox(int $presetId, string $sandboxId): bool;

    /**
     * Unassign sandbox from preset
     *
     * @param int $presetId
     * @return bool
     * @throws PresetException
     */
    public function unassignSandbox(int $presetId): bool;

    /**
     * Get assigned sandbox for preset
     *
     * @param int $presetId
     * @return array|null Array with sandbox info or null if not assigned
     * @throws PresetException
     */
    public function getAssignedSandbox(int $presetId): ?array;

    /**
     * Check if preset has assigned sandbox
     *
     * @param int $presetId
     * @return bool
     */
    public function hasAssignedSandbox(int $presetId): bool;

    /**
     * Get all presets assigned to specific sandbox
     *
     * @param string $sandboxId
     * @return array Array of preset assignments
     */
    public function getPresetsForSandbox(string $sandboxId): array;

    /**
     * Clean up assignments for deleted sandbox
     *
     * @param string $sandboxId
     * @return int Number of presets cleaned up
     */
    public function cleanupSandboxAssignments(string $sandboxId): int;

    /**
     * Validate all sandbox assignments and clean up orphaned ones
     *
     * @return array Cleanup statistics
     */
    public function validateAndCleanupAssignments(): array;

    /**
     * Get sandbox assignment statistics
     *
     * @return array Statistics about assignments
     */
    public function getAssignmentStats(): array;

    /**
     * Create and assign new sandbox for preset
     *
     * @param int $presetId
     * @param string|null $sandboxType
     * @param string|null $sandboxName
     * @return array Array with sandbox info and creation status
     * @throws PresetException
     */
    public function createAndAssignSandbox(int $presetId, ?string $sandboxType = null, ?string $sandboxName = null): array;
}

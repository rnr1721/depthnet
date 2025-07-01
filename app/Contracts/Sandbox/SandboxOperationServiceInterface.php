<?php

declare(strict_types=1);

namespace App\Contracts\Sandbox;

use App\Services\Sandbox\DTO\SandboxInstance;

/**
 * Contract for sandbox operation service
 */
interface SandboxOperationServiceInterface
{
    /**
     * Start sandbox with progress tracking
     *
     * @param string $operationId
     * @param string $sandboxId
     * @return SandboxInstance
     */
    public function startSandbox(string $operationId, string $sandboxId): SandboxInstance;

    /**
     * Stop sandbox with progress tracking
     *
     * @param string $operationId
     * @param string $sandboxId
     * @param integer|null $timeout
     * @return SandboxInstance
     */
    public function stopSandbox(string $operationId, string $sandboxId, ?int $timeout = null): SandboxInstance;

    /**
     * Create a new sandbox with progress tracking
     *
     * @param string $operationId
     * @param string|null $type
     * @param string|null $name
     * @param string|null $ports
     * @return SandboxInstance
     */
    public function createSandbox(
        string $operationId,
        ?string $type = null,
        ?string $name = null,
        ?string $ports = null
    ): SandboxInstance;

    /**
     * Reset sandbox with progress tracking
     *
     * @param string $operationId
     * @param string $sandboxId
     * @param string|null $type
     * @return SandboxInstance
     */
    public function resetSandbox(
        string $operationId,
        string $sandboxId,
        ?string $type = null
    ): SandboxInstance;

    /**
     * Destroy sandbox with progress tracking
     *
     * @param string $operationId
     * @param string $sandboxId
     * @return boolean
     */
    public function destroySandbox(string $operationId, string $sandboxId): bool;

    /**
     * Cleanup all sandboxes with progress tracking
     *
     * @param string $operationId
     * @return integer
     */
    public function cleanupAllSandboxes(string $operationId): int;
}

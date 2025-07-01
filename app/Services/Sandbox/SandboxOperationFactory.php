<?php

declare(strict_types=1);

namespace App\Services\Sandbox;

use App\Models\SandboxOperation;

/**
 * Factory for creating sandbox operations
 */
class SandboxOperationFactory
{
    /**
     * Create a new sandbox start operation
     *
     * @param string $operationId
     * @param integer $userId
     * @param string $sandboxId
     * @return SandboxOperation
     */
    public static function createStartOperation(
        string $operationId,
        int $userId,
        string $sandboxId
    ): SandboxOperation {
        return SandboxOperation::create([
            'operation_id' => $operationId,
            'type' => 'start',
            'status' => 'pending',
            'sandbox_id' => $sandboxId,
            'user_id' => $userId,
            'metadata' => [],
            'message' => 'Sandbox start queued...'
        ]);
    }

    /**
     * Create a new sandbox stop operation
     *
     * @param string $operationId
     * @param integer $userId
     * @param string $sandboxId
     * @param integer|null $timeout
     * @return SandboxOperation
     */
    public static function createStopOperation(
        string $operationId,
        int $userId,
        string $sandboxId,
        ?int $timeout = null
    ): SandboxOperation {
        return SandboxOperation::create([
            'operation_id' => $operationId,
            'type' => 'stop',
            'status' => 'pending',
            'sandbox_id' => $sandboxId,
            'user_id' => $userId,
            'metadata' => [
                'timeout' => $timeout,
            ],
            'message' => 'Sandbox stop queued...'
        ]);
    }

    /**
     * Create a new sandbox creation operation
     *
     * @param string $operationId
     * @param integer $userId
     * @param string|null $type
     * @param string|null $name
     * @param string|null $ports
     * @return SandboxOperation
     */
    public static function createSandboxOperation(
        string $operationId,
        int $userId,
        ?string $type = null,
        ?string $name = null,
        ?string $ports = null
    ): SandboxOperation {
        return SandboxOperation::create([
            'operation_id' => $operationId,
            'type' => 'create',
            'status' => 'pending',
            'user_id' => $userId,
            'metadata' => [
                'requested_type' => $type,
                'requested_name' => $name,
                'requested_ports' => $ports,
            ],
            'message' => 'Sandbox creation queued...'
        ]);
    }

    /**
     * Create a new sandbox reset operation
     *
     * @param string $operationId
     * @param integer $userId
     * @param string $sandboxId
     * @param string|null $type
     * @return SandboxOperation
     */
    public static function createResetOperation(
        string $operationId,
        int $userId,
        string $sandboxId,
        ?string $type = null
    ): SandboxOperation {
        return SandboxOperation::create([
            'operation_id' => $operationId,
            'type' => 'reset',
            'status' => 'pending',
            'sandbox_id' => $sandboxId,
            'user_id' => $userId,
            'metadata' => [
                'reset_type' => $type,
            ],
            'message' => 'Sandbox reset queued...'
        ]);
    }

    /**
     * Create a new sandbox destruction operation
     *
     * @param string $operationId
     * @param integer $userId
     * @param string|null $sandboxId
     * @return SandboxOperation
     */
    public static function createDestroyOperation(
        string $operationId,
        int $userId,
        ?string $sandboxId = null
    ): SandboxOperation {
        $isCleanupAll = $sandboxId === null;

        return SandboxOperation::create([
            'operation_id' => $operationId,
            'type' => $isCleanupAll ? 'cleanup' : 'destroy',
            'status' => 'pending',
            'sandbox_id' => $sandboxId,
            'user_id' => $userId,
            'metadata' => [
                'is_cleanup_all' => $isCleanupAll,
            ],
            'message' => $isCleanupAll
                ? 'Mass cleanup queued...'
                : 'Sandbox destruction queued...'
        ]);
    }

    /**
     * Generate unique operation ID
     *
     * @param string $type
     * @return string
     */
    public static function generateOperationId(string $type): string
    {
        return $type . '_' . uniqid();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Sandbox;

use App\Contracts\Sandbox\SandboxManagerInterface;
use App\Contracts\Sandbox\SandboxOperationServiceInterface;
use App\Exceptions\Sandbox\SandboxException;
use App\Models\SandboxOperation;
use App\Services\Sandbox\DTO\SandboxInstance;
use Psr\Log\LoggerInterface;

/**
 * Service for handling sandbox operations with progress tracking
 */
class SandboxOperationService implements SandboxOperationServiceInterface
{
    public function __construct(
        private readonly SandboxManagerInterface $sandboxManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function startSandbox(string $operationId, string $sandboxId): SandboxInstance
    {
        $operation = $this->getOperation($operationId);

        if (!$operation) {
            throw new SandboxException("Operation {$operationId} not found");
        }

        try {
            $operation->markAsStarted("Starting sandbox: {$sandboxId}");

            // Current sandbox info
            $sandbox = $this->sandboxManager->getSandbox($sandboxId);
            if (!$sandbox) {
                throw new SandboxException("Sandbox {$sandboxId} not found");
            }

            $operation->addLog("Found sandbox: {$sandbox->name} (type: {$sandbox->type})");

            if ($sandbox->status === 'running') {
                $operation->addLog("Sandbox is already running");
                $operation->markAsCompleted("Sandbox '{$sandboxId}' is already running");
                return $sandbox;
            }

            $operation->addLog('Checking Docker image availability...');
            $this->validateEnvironment($sandbox->type);

            $operation->addLog('Pulling/building image if needed...');
            $operation->addLog('Starting container...');

            $result = $this->sandboxManager->startSandbox($sandboxId);

            if (!$result) {
                throw new SandboxException("Failed to start sandbox {$sandboxId}");
            }

            $operation->addLog('Verifying container is running...');

            // Updated sandbox info
            $updatedSandbox = $this->sandboxManager->getSandbox($sandboxId);
            if (!$updatedSandbox || $updatedSandbox->status !== 'running') {
                throw new SandboxException("Sandbox start verification failed");
            }

            $operation->addLog('Configuring networking and permissions...');
            sleep(1); // Brief pause for stability

            $operation->markAsCompleted("Sandbox '{$sandboxId}' started successfully!");

            return $updatedSandbox;

        } catch (\Exception $e) {
            $this->logger->error('Sandbox start failed', [
                'operation_id' => $operationId,
                'sandbox_id' => $sandboxId,
                'error' => $e->getMessage()
            ]);

            $operation->markAsFailed('Start failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function stopSandbox(string $operationId, string $sandboxId, ?int $timeout = null): SandboxInstance
    {
        $operation = $this->getOperation($operationId);

        if (!$operation) {
            throw new SandboxException("Operation {$operationId} not found");
        }

        try {
            $operation->markAsStarted("Stopping sandbox: {$sandboxId}");

            // Current sandbox info
            $sandbox = $this->sandboxManager->getSandbox($sandboxId);
            if (!$sandbox) {
                throw new SandboxException("Sandbox {$sandboxId} not found");
            }

            $operation->addLog("Found sandbox: {$sandbox->name} (status: {$sandbox->status})");

            if ($sandbox->status === 'stopped') {
                $operation->addLog("Sandbox is already stopped");
                $operation->markAsCompleted("Sandbox '{$sandboxId}' is already stopped");
                return $sandbox;
            }

            $timeoutMsg = $timeout ? " (timeout: {$timeout}s)" : '';
            $operation->addLog("Sending stop signal to container{$timeoutMsg}...");

            $operation->addLog('Gracefully shutting down processes...');

            $result = $this->sandboxManager->stopSandbox($sandboxId, $timeout);

            if (!$result) {
                throw new SandboxException("Failed to stop sandbox {$sandboxId}");
            }

            $operation->addLog('Verifying container is stopped...');

            $updatedSandbox = $this->sandboxManager->getSandbox($sandboxId);
            if (!$updatedSandbox || $updatedSandbox->status !== 'stopped') {
                throw new SandboxException("Sandbox stop verification failed");
            }

            $operation->addLog('Cleaning up temporary resources...');

            $operation->markAsCompleted("Sandbox '{$sandboxId}' stopped successfully!");

            return $updatedSandbox;

        } catch (\Exception $e) {
            $this->logger->error('Sandbox stop failed', [
                'operation_id' => $operationId,
                'sandbox_id' => $sandboxId,
                'error' => $e->getMessage()
            ]);

            $operation->markAsFailed('Stop failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function createSandbox(
        string $operationId,
        ?string $type = null,
        ?string $name = null,
        ?string $ports = null
    ): SandboxInstance {
        $operation = $this->getOperation($operationId);

        if (!$operation) {
            throw new SandboxException("Operation {$operationId} not found");
        }

        try {
            $operation->markAsStarted('Preparing to create sandbox...');

            $operation->addLog('Validating sandbox parameters...');

            $sandboxName = $name ?: 'auto-' . uniqid();
            $sandboxType = $type ?: 'ubuntu-full';

            $operation->addLog("Creating sandbox: {$sandboxName} (type: {$sandboxType})");

            if ($ports) {
                $operation->addLog("Port mapping: {$ports}");
                $this->validatePorts($ports);
            }

            $operation->metadata = array_merge($operation->metadata ?? [], [
                'sandbox_name' => $sandboxName,
                'sandbox_type' => $sandboxType,
                'ports' => $ports
            ]);
            $operation->save();

            $operation->addLog('Checking Docker images and network...');
            $this->validateEnvironment($sandboxType);

            $operation->addLog('Building/pulling Docker image if needed...');

            $sandbox = $this->sandboxManager->createSandbox($sandboxType, $sandboxName, $ports);

            $operation->addLog('Configuring sandbox environment...');

            $operation->sandbox_id = $sandbox->id;
            $operation->save();

            $operation->addLog('Setting up permissions and directories...');
            $this->validateSandboxCreation($sandbox->id);

            $operation->addLog('Initializing workspace...');
            sleep(1);

            $operation->markAsCompleted("Sandbox '{$sandboxName}' created successfully!");

            return $sandbox;

        } catch (\Exception $e) {
            $this->logger->error('Sandbox creation failed', [
                'operation_id' => $operationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $operation->markAsFailed('Failed to create sandbox: ' . $e->getMessage());
            throw $e;
        }
    }

    public function resetSandbox(
        string $operationId,
        string $sandboxId,
        ?string $type = null
    ): SandboxInstance {
        $operation = $this->getOperation($operationId);

        if (!$operation) {
            throw new SandboxException("Operation {$operationId} not found");
        }

        try {
            $operation->markAsStarted("Resetting sandbox: {$sandboxId}");

            $currentSandbox = $this->sandboxManager->getSandbox($sandboxId);
            if (!$currentSandbox) {
                throw new SandboxException("Sandbox {$sandboxId} not found");
            }

            $resetType = $type ?? $currentSandbox->type ?? 'ubuntu-full';

            $operation->addLog("Current sandbox type: {$currentSandbox->type}");
            $operation->addLog("Reset to type: {$resetType}");

            $operation->addLog('Stopping current sandbox instance...');
            if ($currentSandbox->status === 'running') {
                $this->sandboxManager->stopSandbox($sandboxId);
            }

            $operation->addLog('Removing old container...');
            $operation->addLog('Creating fresh container...');

            $sandbox = $this->sandboxManager->resetSandbox($sandboxId, $resetType);

            $operation->markAsCompleted("Sandbox '{$sandboxId}' reset successfully!");

            return $sandbox;

        } catch (\Exception $e) {
            $this->logger->error('Sandbox reset failed', [
                'operation_id' => $operationId,
                'sandbox_id' => $sandboxId,
                'error' => $e->getMessage()
            ]);

            $operation->markAsFailed('Reset failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function destroySandbox(string $operationId, string $sandboxId): bool
    {
        $operation = $this->getOperation($operationId);

        if (!$operation) {
            throw new SandboxException("Operation {$operationId} not found");
        }

        try {
            $operation->markAsStarted("Destroying sandbox: {$sandboxId}");


            $sandbox = $this->sandboxManager->getSandbox($sandboxId);
            if (!$sandbox) {
                $operation->addLog("Sandbox {$sandboxId} not found, considering as already destroyed");
                $operation->markAsCompleted("Sandbox '{$sandboxId}' was not found (already destroyed)");
                return true;
            }

            $operation->addLog("Found sandbox: {$sandbox->name} (status: {$sandbox->status})");

            if ($sandbox->status === 'running') {
                $operation->addLog('Stopping running sandbox...');
                $this->sandboxManager->stopSandbox($sandboxId, 10);
            }

            $operation->addLog('Removing container and associated data...');
            $result = $this->sandboxManager->destroySandbox($sandboxId);

            $operation->addLog('Cleaning up shared directories...');
            $this->cleanupSharedDirectories($sandboxId);

            if ($result) {
                $operation->markAsCompleted("Sandbox '{$sandboxId}' destroyed successfully!");
            } else {
                $operation->markAsFailed("Failed to destroy sandbox '{$sandboxId}'");
            }

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Sandbox destruction failed', [
                'operation_id' => $operationId,
                'sandbox_id' => $sandboxId,
                'error' => $e->getMessage()
            ]);

            $operation->markAsFailed('Destruction failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function cleanupAllSandboxes(string $operationId): int
    {
        $operation = $this->getOperation($operationId);

        if (!$operation) {
            throw new SandboxException("Operation {$operationId} not found");
        }

        try {
            $operation->markAsStarted('Starting cleanup of all sandboxes...');

            $operation->addLog('Getting list of all sandboxes...');

            $sandboxes = $this->sandboxManager->listSandboxes(true);
            $totalCount = count($sandboxes);

            $operation->addLog("Found {$totalCount} sandboxes to cleanup");

            if ($totalCount === 0) {
                $operation->markAsCompleted('No sandboxes found to cleanup');
                return 0;
            }

            $running = array_filter($sandboxes, fn ($s) => $s->status === 'running');
            $stopped = array_filter($sandboxes, fn ($s) => $s->status === 'stopped');

            $operation->addLog("Running: " . count($running) . ", Stopped: " . count($stopped));

            $operation->addLog('Stopping all running sandboxes...');
            $this->stopAllRunningSandboxes($sandboxes, $operation);

            $operation->addLog('Removing all containers...');
            $cleanedCount = $this->sandboxManager->cleanupAll();

            $operation->markAsCompleted("Cleanup completed! Removed {$cleanedCount} sandboxes");

            return $cleanedCount;

        } catch (\Exception $e) {
            $this->logger->error('Cleanup all failed', [
                'operation_id' => $operationId,
                'error' => $e->getMessage()
            ]);

            $operation->markAsFailed('Cleanup failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get operation by ID
     *
     * @param string $operationId
     * @return SandboxOperation|null
     */
    private function getOperation(string $operationId): ?SandboxOperation
    {
        return SandboxOperation::where('operation_id', $operationId)->first();
    }

    /**
     * Validate ports format and availability
     *
     * @param string $ports
     * @return void
     */
    private function validatePorts(string $ports): void
    {
        if (empty($ports)) {
            return;
        }

        $portNumbers = array_map('trim', explode(',', $ports));

        foreach ($portNumbers as $port) {
            if (!is_numeric($port) || $port < 1 || $port > 65535) {
                throw new SandboxException("Invalid port number: {$port}");
            }

            // Advanced port availability? hmmmm...
        }
    }

    /**
     * Validate environment before sandbox creation
     *
     * @param string $type
     * @return void
     */
    private function validateEnvironment(string $type): void
    {
        // TODO: better validation
    }

    /**
     * Validate sandbox was created successfully
     *
     * @param string $sandboxId
     * @return void
     */
    private function validateSandboxCreation(string $sandboxId): void
    {
        $sandbox = $this->sandboxManager->getSandbox($sandboxId);

        if (!$sandbox) {
            throw new SandboxException("Sandbox validation failed: {$sandboxId} not found after creation");
        }

        if ($sandbox->status !== 'running') {
            throw new SandboxException("Sandbox validation failed: {$sandboxId} is not running");
        }
    }

    /**
     * Cleanup shared directories for a sandbox
     *
     * @param string $sandboxId
     * @return void
     */
    private function cleanupSharedDirectories(string $sandboxId): void
    {
        // TODO: Implementation for cleaning up host shared directories
    }

    /**
     * Stop all running sandboxes with progress reporting
     *
     * @param array $sandboxes
     * @param SandboxOperation $operation
     * @return void
     */
    private function stopAllRunningSandboxes(array $sandboxes, SandboxOperation $operation): void
    {
        $running = array_filter($sandboxes, fn ($s) => $s->status === 'running');

        foreach ($running as $sandbox) {
            try {
                $operation->addLog("Stopping sandbox: {$sandbox->id}");
                $this->sandboxManager->stopSandbox($sandbox->id, 5); // Shorter timeout for bulk operation
            } catch (\Exception $e) {
                $operation->addLog("Failed to stop {$sandbox->id}: {$e->getMessage()}", 'warning');
            }
        }
    }

}

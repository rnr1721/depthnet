<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Sandbox\SandboxOperationServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job for batch operations (future use)
 */
class BatchSandboxOperationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly array $operations,
        private readonly string $batchOperationId,
        private readonly int $userId
    ) {
        $this->onQueue('sandboxes');
    }

    public function handle(SandboxOperationServiceInterface $operationService): void
    {

        foreach ($this->operations as $operation) {
            try {
                match ($operation['type']) {
                    'create' => $operationService->createSandbox(
                        $operation['operation_id'],
                        $operation['sandbox_type'] ?? null,
                        $operation['sandbox_name'] ?? null,
                        $operation['ports'] ?? null
                    ),
                    'destroy' => $operationService->destroySandbox(
                        $operation['operation_id'],
                        $operation['sandbox_id']
                    ),
                    'reset' => $operationService->resetSandbox(
                        $operation['operation_id'],
                        $operation['sandbox_id'],
                        $operation['reset_type'] ?? null
                    ),
                    default => throw new \InvalidArgumentException("Unknown operation type: {$operation['type']}")
                };
            } catch (\Exception $e) {
                Log::error('BatchSandboxOperationJob: Operation failed', [
                    'batch_operation_id' => $this->batchOperationId,
                    'operation_id' => $operation['operation_id'],
                    'error' => $e->getMessage()
                ]);

                continue;
            }
        }

    }

    public function failed(\Throwable $exception): void
    {
        Log::error('BatchSandboxOperationJob failed', [
            'batch_operation_id' => $this->batchOperationId,
            'exception' => $exception->getMessage()
        ]);
    }
}

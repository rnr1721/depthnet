<?php

namespace App\Jobs;

use App\Contracts\Sandbox\SandboxOperationServiceInterface;
use App\Models\SandboxOperation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job for resetting sandbox containers
 */
class ResetSandboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $sandboxId,
        private readonly ?string $type,
        private readonly string $operationId,
        private readonly int $userId
    ) {
        $this->onQueue('sandboxes');
    }

    public function handle(SandboxOperationServiceInterface $operationService): void
    {

        try {
            $sandbox = $operationService->resetSandbox(
                $this->operationId,
                $this->sandboxId,
                $this->type
            );

            Log::info('ResetSandboxJob completed successfully', [
                'operation_id' => $this->operationId,
                'sandbox_id' => $sandbox->id
            ]);

        } catch (\Exception $e) {
            Log::error('ResetSandboxJob failed', [
                'operation_id' => $this->operationId,
                'sandbox_id' => $this->sandboxId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ResetSandboxJob failed with exception', [
            'operation_id' => $this->operationId,
            'sandbox_id' => $this->sandboxId,
            'exception' => $exception->getMessage()
        ]);

        $operation = SandboxOperation::where('operation_id', $this->operationId)->first();
        if ($operation && $operation->status !== 'failed') {
            $operation->markAsFailed('Job failed: ' . $exception->getMessage());
        }
    }
}

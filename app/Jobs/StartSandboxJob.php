<?php

declare(strict_types=1);

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
 * Job for starting sandbox containers with progress tracking
 *
 * This job handles starting existing sandbox containers, including image download if needed
 */
class StartSandboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $sandboxId,
        private readonly string $operationId,
        private readonly int $userId
    ) {
        // Configure queue settings
        $this->onQueue('sandboxes');
    }

    /**
     * Execute the job
     */
    public function handle(SandboxOperationServiceInterface $operationService): void
    {

        try {
            $sandbox = $operationService->startSandbox(
                $this->operationId,
                $this->sandboxId
            );

            Log::info('StartSandboxJob completed successfully', [
                'operation_id' => $this->operationId,
                'sandbox_id' => $this->sandboxId,
                'status' => $sandbox->status
            ]);

        } catch (\Exception $e) {
            Log::error('StartSandboxJob failed', [
                'operation_id' => $this->operationId,
                'sandbox_id' => $this->sandboxId,
                'error' => $e->getMessage(),
                'user_id' => $this->userId
            ]);

            // The service already handles operation status updates
            throw $e;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('StartSandboxJob failed with exception', [
            'operation_id' => $this->operationId,
            'sandbox_id' => $this->sandboxId,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Update operation status if service didn't handle it
        $operation = SandboxOperation::where('operation_id', $this->operationId)->first();
        if ($operation && $operation->status !== 'failed') {
            $operation->markAsFailed('Job failed: ' . $exception->getMessage());
        }
    }
}

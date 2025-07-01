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
 * Job for destroying sandbox containers
 */
class DestroySandboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly ?string $sandboxId, // null means cleanup all
        private readonly string $operationId,
        private readonly int $userId
    ) {
        $this->onQueue('sandboxes');
    }

    public function handle(SandboxOperationServiceInterface $operationService): void
    {
        if ($this->sandboxId) {

            try {
                $result = $operationService->destroySandbox($this->operationId, $this->sandboxId);

            } catch (\Exception $e) {
                Log::error('DestroySandboxJob failed', [
                    'operation_id' => $this->operationId,
                    'sandbox_id' => $this->sandboxId,
                    'error' => $e->getMessage()
                ]);

                throw $e;
            }
        } else {

            try {
                $cleanedCount = $operationService->cleanupAllSandboxes($this->operationId);

                Log::info('DestroySandboxJob cleanup completed', [
                    'operation_id' => $this->operationId,
                    'cleaned_count' => $cleanedCount
                ]);

            } catch (\Exception $e) {
                Log::error('DestroySandboxJob cleanup failed', [
                    'operation_id' => $this->operationId,
                    'error' => $e->getMessage()
                ]);

                throw $e;
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DestroySandboxJob failed with exception', [
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

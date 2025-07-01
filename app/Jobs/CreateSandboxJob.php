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
 * Job for creating sandbox containers with progress tracking
 *
 * This job only handles queue coordination and delegates actual work to SandboxOperationService
 */
class CreateSandboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $timeout = 0;

    public function __construct(
        private readonly ?string $type,
        private readonly ?string $name,
        private readonly ?string $ports,
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
            $sandbox = $operationService->createSandbox(
                $this->operationId,
                $this->type,
                $this->name,
                $this->ports
            );

        } catch (\Exception $e) {
            Log::error('CreateSandboxJob failed', [
                'operation_id' => $this->operationId,
                'error' => $e->getMessage(),
                'user_id' => $this->userId
            ]);

            throw $e;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CreateSandboxJob failed with exception', [
            'operation_id' => $this->operationId,
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

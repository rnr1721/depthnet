<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\Auth\AuthServiceInterface;
use App\Contracts\Sandbox\SandboxManagerInterface;
use App\Contracts\Sandbox\SandboxServiceInterface;
use App\Contracts\Agent\PresetSandboxServiceInterface;
use App\Exceptions\Sandbox\SandboxException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Sandbox\CreateSandboxRequest;
use App\Http\Requests\Admin\Sandbox\ExecuteCodeRequest;
use App\Http\Requests\Admin\Sandbox\ExecuteCommandRequest;
use App\Http\Requests\Admin\Sandbox\InstallPackagesRequest;
use App\Http\Requests\Admin\Sandbox\ResetSandboxRequest;
use App\Http\Requests\Admin\Sandbox\StopSandboxRequest;
use App\Jobs\CreateSandboxJob;
use App\Jobs\DestroySandboxJob;
use App\Jobs\ResetSandboxJob;
use App\Jobs\StartSandboxJob;
use App\Jobs\StopSandboxJob;
use App\Models\SandboxOperation;
use App\Services\Sandbox\SandboxOperationFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sandbox hypervisor controller for managing containerized environments
 */
class SandboxController extends Controller
{
    public function __construct(
        private readonly SandboxManagerInterface $sandboxManager,
        private readonly SandboxServiceInterface $sandboxService,
        private readonly AuthServiceInterface $authService,
        private readonly PresetSandboxServiceInterface $presetSandboxService
    ) {
    }

    /**
     * Display sandbox management page
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        try {
            $includeAll = $request->boolean('show_all', false);
            $sandboxes = $this->sandboxManager->listSandboxes($includeAll);
            $supportedOptions = $this->sandboxService->getSupportedLanguages();

            // Recent operations for current user
            $recentOperations = $this->getUserRecentOperations();

            return Inertia::render('Admin/Sandboxes/Index', [
                'sandboxes' => array_map(fn ($sandbox) => $sandbox->toArray(), $sandboxes),
                'supportedLanguages' => $supportedOptions,
                'sandboxTypes' => $this->sandboxManager->getSandboxTypes(),
                'stats' => $this->sandboxManager->getStats($sandboxes),
                'showAll' => $includeAll,
                'recentOperations' => $recentOperations
            ]);
        } catch (SandboxException $e) {
            Log::error('Failed to load sandbox management page', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Inertia::render('Admin/Sandboxes/Index', [
                'sandboxes' => [],
                'supportedLanguages' => [],
                'sandboxTypes' => [],
                'stats' => ['total' => 0, 'running' => 0, 'stopped' => 0],
                'showAll' => false,
                'recentOperations' => [],
                'error' => 'Failed to load sandbox data: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get list of all sandbox containers (API)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function list(Request $request): JsonResponse
    {
        try {
            $includeAll = $request->boolean('include_all', false);
            $sandboxes = $this->sandboxManager->listSandboxes($includeAll);

            return response()->json([
                'success' => true,
                'data' => array_map(fn ($sandbox) => $sandbox->toArray(), $sandboxes),
                'total' => count($sandboxes),
                'stats' => $this->sandboxManager->getStats($sandboxes)
            ]);
        } catch (SandboxException $e) {
            Log::error('Failed to list sandboxes API', [
                'error' => $e->getMessage(),
                'user_id' => $this->authService->getCurrentUserId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sandbox list: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new sandbox container (queued)
     *
     * @param CreateSandboxRequest $request
     * @return JsonResponse
     */
    public function store(CreateSandboxRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $userId = $this->authService->getCurrentUserId();

            $operationId = SandboxOperationFactory::generateOperationId('create');

            // Create operation record using factory
            $operation = SandboxOperationFactory::createSandboxOperation(
                $operationId,
                $userId,
                $validated['type'] ?? null,
                $validated['name'] ?? null,
                $validated['ports'] ?? null
            );

            // Dispatch job for async processing
            CreateSandboxJob::dispatch(
                $validated['type'] ?? null,
                $validated['name'] ?? null,
                $validated['ports'] ?? null,
                $operationId,
                $userId
            );

            return response()->json([
                'success' => true,
                'message' => 'Sandbox creation started. Please wait...',
                'operation_id' => $operationId,
                'operation' => $this->formatOperationForResponse($operation)
            ], 202);

        } catch (\Exception $e) {
            Log::error('Failed to create sandbox', [
                'error' => $e->getMessage(),
                'user_id' => $this->authService->getCurrentUserId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create sandbox: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start a stopped sandbox
     */
    public function start(string $sandboxId): JsonResponse
    {
        try {
            $userId = $this->authService->getCurrentUserId();

            $sandbox = $this->sandboxManager->getSandbox($sandboxId);
            if (!$sandbox) {
                return response()->json([
                    'success' => false,
                    'message' => "Sandbox '{$sandboxId}' not found"
                ], 404);
            }

            if ($sandbox->status === 'running') {
                return response()->json([
                    'success' => false,
                    'message' => "Sandbox '{$sandboxId}' is already running"
                ], 400);
            }

            $operationId = SandboxOperationFactory::generateOperationId('start');

            // Operation record
            $operation = SandboxOperationFactory::createStartOperation(
                $operationId,
                $userId,
                $sandboxId
            );

            // Dispatch job for async processing
            StartSandboxJob::dispatch($sandboxId, $operationId, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Sandbox start initiated. Please wait...',
                'operation_id' => $operationId,
                'operation' => $this->formatOperationForResponse($operation)
            ], 202);

        } catch (SandboxException $e) {
            Log::error('Failed to start sandbox', [
                'sandbox_id' => $sandboxId,
                'error' => $e->getMessage(),
                'user_id' => $this->authService->getCurrentUserId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start sandbox: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Stop a running sandbox (now async)
     */
    public function stop(StopSandboxRequest $request, string $sandboxId): JsonResponse
    {
        try {
            $validated = $request->validated();
            $userId = $this->authService->getCurrentUserId();

            // Exists?
            $sandbox = $this->sandboxManager->getSandbox($sandboxId);
            if (!$sandbox) {
                return response()->json([
                    'success' => false,
                    'message' => "Sandbox '{$sandboxId}' not found"
                ], 404);
            }

            if ($sandbox->status === 'stopped') {
                return response()->json([
                    'success' => false,
                    'message' => "Sandbox '{$sandboxId}' is already stopped"
                ], 400);
            }

            // Make operation ID
            $operationId = SandboxOperationFactory::generateOperationId('stop');

            $operation = SandboxOperationFactory::createStopOperation(
                $operationId,
                $userId,
                $sandboxId,
                $validated['timeout'] ?? null
            );

            StopSandboxJob::dispatch(
                $sandboxId,
                $operationId,
                $userId,
                $validated['timeout'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Sandbox stop initiated. Please wait...',
                'operation_id' => $operationId,
                'operation' => $this->formatOperationForResponse($operation)
            ], 202);

        } catch (SandboxException $e) {
            Log::error('Failed to stop sandbox', [
                'sandbox_id' => $sandboxId,
                'error' => $e->getMessage(),
                'user_id' => $this->authService->getCurrentUserId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to stop sandbox: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sandbox details and environment info
     *
     * @param string $sandboxId
     * @return JsonResponse
     */
    public function show(string $sandboxId): JsonResponse
    {
        try {
            $sandbox = $this->sandboxManager->getSandbox($sandboxId);

            if (!$sandbox) {
                return response()->json([
                    'success' => false,
                    'message' => "Sandbox '{$sandboxId}' not found"
                ], 404);
            }

            // Get environment info (may be slow, but not queued since it's user-initiated)
            $environment = null;
            try {
                $environment = $this->sandboxService->getSandboxEnvironment($sandboxId);
            } catch (SandboxException $e) {
                Log::warning('Failed to get sandbox environment', [
                    'sandbox_id' => $sandboxId,
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'sandbox' => $sandbox->toArray(),
                    'environment' => $environment?->toArray()
                ]
            ]);

        } catch (SandboxException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sandbox details: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Execute command in sandbox (direct execution - user expects immediate feedback)
     *
     * @param ExecuteCommandRequest $request
     * @param string $sandboxId
     * @return JsonResponse
     */
    public function executeCommand(ExecuteCommandRequest $request, string $sandboxId): JsonResponse
    {
        try {
            $validated = $request->validated();

            $result = $this->sandboxManager->executeCommand(
                $sandboxId,
                $validated['command'],
                $validated['user'] ?? 'sandbox-user',
                $validated['timeout'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => $result->toArray()
            ]);

        } catch (SandboxException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Command execution failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Execute code in sandbox (direct execution)
     *
     * @param ExecuteCodeRequest $request
     * @param string $sandboxId
     * @return JsonResponse
     */
    public function executeCode(ExecuteCodeRequest $request, string $sandboxId): JsonResponse
    {
        try {
            $validated = $request->validated();

            $options = [
                'timeout' => $validated['timeout'] ?? null,
                'auto_cleanup' => false
            ];

            if (!empty($validated['filename'])) {
                $options['filename'] = $validated['filename'];
            }

            $result = $this->sandboxService->executeCodeInSandbox(
                $sandboxId,
                $validated['code'],
                $validated['language'],
                $options
            );

            return response()->json([
                'success' => true,
                'data' => $result->toArray()
            ]);

        } catch (SandboxException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Code execution failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset sandbox to clean state (queued)
     *
     * @param ResetSandboxRequest $request
     * @param string $sandboxId
     * @return JsonResponse
     */
    public function reset(ResetSandboxRequest $request, string $sandboxId): JsonResponse
    {
        try {
            $validated = $request->validated();
            $userId = $this->authService->getCurrentUserId();

            // Sandbox exists?
            $sandbox = $this->sandboxManager->getSandbox($sandboxId);
            if (!$sandbox) {
                return response()->json([
                    'success' => false,
                    'message' => "Sandbox '{$sandboxId}' not found"
                ], 404);
            }

            $operationId = SandboxOperationFactory::generateOperationId('reset');

            // New operation record
            $operation = SandboxOperationFactory::createResetOperation(
                $operationId,
                $userId,
                $sandboxId,
                $validated['type'] ?? $sandbox->type
            );

            ResetSandboxJob::dispatch(
                $sandboxId,
                $validated['type'] ?? $sandbox->type,
                $operationId,
                $userId
            );

            return response()->json([
                'success' => true,
                'message' => 'Sandbox reset started. Please wait...',
                'operation_id' => $operationId,
                'operation' => $this->formatOperationForResponse($operation)
            ], 202);

        } catch (SandboxException $e) {
            Log::error('Failed to reset sandbox', [
                'sandbox_id' => $sandboxId,
                'error' => $e->getMessage(),
                'user_id' => $this->authService->getCurrentUserId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reset sandbox: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Install packages in sandbox (direct execution with progress feedback)
     *
     * @param InstallPackagesRequest $request
     * @param string $sandboxId
     * @return JsonResponse
     */
    public function installPackages(InstallPackagesRequest $request, string $sandboxId): JsonResponse
    {
        try {
            $validated = $request->validated();

            $result = $this->sandboxService->installPackages(
                $sandboxId,
                $validated['packages'],
                $validated['language']
            );

            return response()->json([
                'success' => true,
                'data' => $result->toArray(),
                'message' => $result->success
                    ? 'All packages installed successfully'
                    : 'Some packages failed to install'
            ]);

        } catch (SandboxException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Package installation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete sandbox container (queued) - with preset cleanup
     *
     * @param string $sandboxId
     * @return JsonResponse
     */
    public function destroy(string $sandboxId): JsonResponse
    {
        try {
            $userId = $this->authService->getCurrentUserId();

            // Clean up preset assignments before destroying sandbox
            $cleanedPresets = $this->presetSandboxService->cleanupSandboxAssignments($sandboxId);

            $operationId = SandboxOperationFactory::generateOperationId('destroy');

            // Operation record
            $operation = SandboxOperationFactory::createDestroyOperation(
                $operationId,
                $userId,
                $sandboxId
            );

            DestroySandboxJob::dispatch(
                $sandboxId,
                $operationId,
                $userId
            );

            $message = $cleanedPresets > 0
                ? "Sandbox destruction started. Cleaned {$cleanedPresets} preset assignments. Please wait..."
                : 'Sandbox destruction started. Please wait...';

            return response()->json([
                'success' => true,
                'message' => $message,
                'operation_id' => $operationId,
                'operation' => $this->formatOperationForResponse($operation),
                'cleaned_presets' => $cleanedPresets
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate sandbox destruction: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cleanup all sandbox containers (queued mass operation) - with preset cleanup
     *
     * @return JsonResponse
     */
    public function cleanup(): JsonResponse
    {
        try {
            $userId = $this->authService->getCurrentUserId();
            $operationId = SandboxOperationFactory::generateOperationId('cleanup');

            $sandboxes = $this->sandboxManager->listSandboxes(true);
            $count = count($sandboxes);

            // Clean up all preset assignments before mass cleanup
            $totalCleanedPresets = 0;
            foreach ($sandboxes as $sandbox) {
                $totalCleanedPresets += $this->presetSandboxService->cleanupSandboxAssignments($sandbox->id);
            }

            $operation = SandboxOperationFactory::createDestroyOperation(
                $operationId,
                $userId,
                null // null - cleanup all
            );

            $operation->metadata = array_merge($operation->metadata ?? [], [
                'estimated_count' => $count,
                'cleaned_presets' => $totalCleanedPresets
            ]);
            $operation->message = "Mass cleanup of {$count} sandboxes queued. Cleaned {$totalCleanedPresets} preset assignments.";
            $operation->save();

            // Cleanup job
            DestroySandboxJob::dispatch(
                null, // null means cleanup all
                $operationId,
                $userId
            );

            return response()->json([
                'success' => true,
                'message' => "Mass cleanup of {$count} sandboxes started. Cleaned {$totalCleanedPresets} preset assignments. Please wait...",
                'operation_id' => $operationId,
                'estimated_count' => $count,
                'cleaned_presets' => $totalCleanedPresets,
                'operation' => $this->formatOperationForResponse($operation)
            ], 202);

        } catch (SandboxException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cleanup failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get operation status (for polling job progress)
     *
     * @param string $operationId
     * @return JsonResponse
     */
    public function getOperationStatus(string $operationId): JsonResponse
    {
        $operation = SandboxOperation::where('operation_id', $operationId)
            ->where('user_id', $this->authService->getCurrentUserId())
            ->first();

        if (!$operation) {
            return response()->json([
                'success' => false,
                'message' => 'Operation not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatOperationForResponse($operation)
        ]);
    }

    /**
     * Get recent operations for user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getRecentOperations(Request $request): JsonResponse
    {
        $limit = $request->integer('limit', 10);
        $operations = $this->getUserRecentOperations($limit);

        return response()->json([
            'success' => true,
            'data' => $operations
        ]);
    }

    /**
     * Clear old completed operations
     *
     * @return JsonResponse
     */
    public function clearOperations(): JsonResponse
    {
        $deleted = SandboxOperation::where('user_id', $this->authService->getCurrentUserId())
            ->whereIn('status', ['completed', 'failed'])
            ->where('created_at', '<', now()->subHours(24))
            ->delete();

        return response()->json([
            'success' => true,
            'message' => "Cleared {$deleted} old operations"
        ]);
    }

    /**
     * Get supported languages and sandbox types
     *
     * @return JsonResponse
     */
    public function getSupportedOptions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'languages' => $this->sandboxService->getSupportedLanguages(),
                'sandbox_types' => $this->sandboxManager->getSandboxTypes()
            ]
        ]);
    }

    /**
     * Get sandbox configuration for frontend
     *
     * @return JsonResponse
     */
    public function getConfig(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'languages' => config('sandbox.languages'),
                'templates' => config('sandbox.templates'),
                'quick_commands' => config('sandbox.quick_commands'),
                'sandbox_types' => $this->sandboxManager->getSandboxTypes()
            ]
        ]);
    }

    /**
     * Get recent operations for current user
     *
     * @param integer $limit
     * @return array
     */
    private function getUserRecentOperations(int $limit = 10): array
    {
        return SandboxOperation::where('user_id', $this->authService->getCurrentUserId())
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($op) => $this->formatOperationForResponse($op))
            ->toArray();
    }

    /**
     * Format operation for API response
     *
     * @param SandboxOperation $operation
     * @return array
     */
    private function formatOperationForResponse(SandboxOperation $operation): array
    {
        return [
            'id' => $operation->id,
            'operation_id' => $operation->operation_id,
            'type' => $operation->type,
            'type_name' => $operation->type_name,
            'status' => $operation->status,
            'sandbox_id' => $operation->sandbox_id,
            'message' => $operation->message,
            'progress' => $operation->progress,
            'logs' => $operation->logs ?? [],
            'metadata' => $operation->metadata,
            'created_at' => $operation->created_at,
            'started_at' => $operation->started_at,
            'completed_at' => $operation->completed_at,
        ];
    }
}

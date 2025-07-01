<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Contracts\Agent\PresetSandboxServiceInterface;
use App\Exceptions\PresetException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing preset-sandbox relationships
 */
class PresetSandboxController extends Controller
{
    public function __construct(
        protected PresetSandboxServiceInterface $presetSandboxService,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * Get assigned sandbox for preset
     *
     * @param int $presetId
     * @return JsonResponse
     */
    public function getAssignedSandbox(int $presetId): JsonResponse
    {
        try {
            $assignment = $this->presetSandboxService->getAssignedSandbox($presetId);

            if (!$assignment) {
                return response()->json([
                    'success' => true,
                    'data' => null,
                    'message' => 'No sandbox assigned to this preset'
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'sandbox_id' => $assignment['sandbox_id'],
                    'sandbox' => $assignment['sandbox']->toArray(),
                    'assigned_at' => $assignment['assigned_at'],
                    'sandbox_type' => $assignment['sandbox_type']
                ]
            ]);

        } catch (PresetException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get assigned sandbox', [
                'preset_id' => $presetId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sandbox assignment'
            ], 500);
        }
    }

    /**
     * Assign existing sandbox to preset
     *
     * @param Request $request
     * @param int $presetId
     * @return JsonResponse
     */
    public function assignSandbox(Request $request, int $presetId): JsonResponse
    {
        $request->validate([
            'sandbox_id' => 'required|string|max:255'
        ]);

        try {
            $this->presetSandboxService->assignSandbox($presetId, $request->sandbox_id);

            return response()->json([
                'success' => true,
                'message' => 'Sandbox assigned successfully'
            ]);

        } catch (PresetException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            $this->logger->error('Failed to assign sandbox', [
                'preset_id' => $presetId,
                'sandbox_id' => $request->sandbox_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to assign sandbox'
            ], 500);
        }
    }

    /**
     * Unassign sandbox from preset
     *
     * @param int $presetId
     * @return JsonResponse
     */
    public function unassignSandbox(int $presetId): JsonResponse
    {
        try {
            $this->presetSandboxService->unassignSandbox($presetId);

            return response()->json([
                'success' => true,
                'message' => 'Sandbox unassigned successfully'
            ]);

        } catch (PresetException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        } catch (\Exception $e) {
            $this->logger->error('Failed to unassign sandbox', [
                'preset_id' => $presetId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to unassign sandbox'
            ], 500);
        }
    }

    /**
     * Create new sandbox and assign to preset
     *
     * @param Request $request
     * @param int $presetId
     * @return JsonResponse
     */
    public function createAndAssignSandbox(Request $request, int $presetId): JsonResponse
    {
        $request->validate([
            'sandbox_type' => 'nullable|string|max:100',
            'sandbox_name' => 'nullable|string|max:255'
        ]);

        try {
            $result = $this->presetSandboxService->createAndAssignSandbox(
                $presetId,
                $request->sandbox_type,
                $request->sandbox_name
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'sandbox_id' => $result['sandbox_id'],
                    'sandbox' => $result['sandbox']->toArray(),
                    'created' => $result['created']
                ],
                'message' => 'Sandbox created and assigned successfully'
            ], 201);

        } catch (PresetException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create and assign sandbox', [
                'preset_id' => $presetId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create and assign sandbox'
            ], 500);
        }
    }

    /**
     * Get all presets assigned to specific sandbox
     *
     * @param string $sandboxId
     * @return JsonResponse
     */
    public function getPresetsForSandbox(string $sandboxId): JsonResponse
    {
        try {
            $presets = $this->presetSandboxService->getPresetsForSandbox($sandboxId);

            return response()->json([
                'success' => true,
                'data' => array_map(function ($item) {
                    return [
                        'preset' => [
                            'id' => $item['preset']->id,
                            'name' => $item['preset']->name,
                            'engine_name' => $item['preset']->engine_name,
                            'is_active' => $item['preset']->is_active,
                            'is_default' => $item['preset']->is_default
                        ],
                        'assigned_at' => $item['assigned_at'],
                        'sandbox_type' => $item['sandbox_type']
                    ];
                }, $presets)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get presets for sandbox', [
                'sandbox_id' => $sandboxId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve presets'
            ], 500);
        }
    }

    /**
     * Clean up assignments for deleted sandbox
     *
     * @param string $sandboxId
     * @return JsonResponse
     */
    public function cleanupSandboxAssignments(string $sandboxId): JsonResponse
    {
        try {
            $cleanedCount = $this->presetSandboxService->cleanupSandboxAssignments($sandboxId);

            return response()->json([
                'success' => true,
                'data' => [
                    'cleaned_presets' => $cleanedCount
                ],
                'message' => "Cleaned up {$cleanedCount} preset assignments"
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup sandbox assignments', [
                'sandbox_id' => $sandboxId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cleanup assignments'
            ], 500);
        }
    }

    /**
     * Validate and clean up all orphaned assignments
     *
     * @return JsonResponse
     */
    public function validateAndCleanupAll(): JsonResponse
    {
        try {
            $stats = $this->presetSandboxService->validateAndCleanupAssignments();

            return response()->json([
                'success' => true,
                'data' => $stats,
                'message' => "Validation completed. Cleaned {$stats['cleaned']} orphaned assignments."
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to validate and cleanup assignments', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to validate assignments'
            ], 500);
        }
    }

    /**
     * Get assignment statistics
     *
     * @return JsonResponse
     */
    public function getStats(): JsonResponse
    {
        try {
            $stats = $this->presetSandboxService->getAssignmentStats();

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get assignment stats', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics'
            ], 500);
        }
    }

    /**
     * Check if preset has assigned sandbox
     *
     * @param int $presetId
     * @return JsonResponse
     */
    public function hasAssignedSandbox(int $presetId): JsonResponse
    {
        try {
            $hasAssignment = $this->presetSandboxService->hasAssignedSandbox($presetId);

            return response()->json([
                'success' => true,
                'data' => [
                    'has_assignment' => $hasAssignment
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check assignment'
            ], 500);
        }
    }
}

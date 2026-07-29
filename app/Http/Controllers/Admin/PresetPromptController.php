<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\PresetPromptServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Preset\CreatePresetPromptRequest;
use App\Http\Requests\Admin\Preset\UpdatePresetPromptRequest;
use App\Http\Resources\Admin\PresetPromptResource;
use App\Http\Resources\Admin\PresetPromptVersionResource;
use App\Models\PresetPromptVersion;
use Illuminate\Http\JsonResponse;

/**
 * Manages prompts for a given preset.
 *
 * All routes are nested under /admin/presets/{id}/prompts
 *
 * Edits made here are attributed to the acting user: writes pass
 * edited_by = 'human' and the authenticated user id, so version history
 * distinguishes admin edits from the agent's own self-edits.
 */
class PresetPromptController extends Controller
{
    public function __construct(
        protected PresetServiceInterface $presetService,
        protected PresetPromptServiceInterface $promptService
    ) {
    }

    /**
     * GET /admin/presets/{id}/prompts
     *
     * List all prompts for a preset.
     */
    public function index(int $id): JsonResponse
    {
        try {
            $preset = $this->presetService->findById($id);

            if (!$preset) {
                return $this->errorResponse('Preset not found', 404);
            }

            $prompts   = $this->promptService->getAll($preset);
            $activeId  = $preset->active_prompt_id;

            return $this->successResponse([
                'prompts'           => PresetPromptResource::collection($prompts),
                'active_prompt_id'  => $activeId,
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve prompts');
        }
    }

    /**
     * POST /admin/presets/{id}/prompts
     *
     * Create a new prompt for a preset.
     */
    public function store(CreatePresetPromptRequest $request, int $id): JsonResponse
    {
        try {
            $preset = $this->presetService->findById($id);

            if (!$preset) {
                return $this->errorResponse('Preset not found', 404);
            }

            $data      = $request->validated();
            $setActive = (bool) ($data['set_as_active'] ?? false);

            $prompt = $this->promptService->create(
                $preset,
                $data,
                $setActive,
                PresetPromptVersion::BY_HUMAN,
                $request->user()?->id
            );

            return $this->successResponse([
                'prompt'           => new PresetPromptResource($prompt),
                'active_prompt_id' => $preset->fresh()->active_prompt_id,
            ], 201);

        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to create prompt');
        }
    }

    /**
     * PUT /admin/presets/{id}/prompts/{promptId}
     *
     * Update a prompt.
     */
    public function update(UpdatePresetPromptRequest $request, int $id, int $promptId): JsonResponse
    {
        try {
            $preset = $this->presetService->findById($id);

            if (!$preset) {
                return $this->errorResponse('Preset not found', 404);
            }

            $prompt = $this->promptService->update(
                $preset,
                $promptId,
                $request->validated(),
                PresetPromptVersion::BY_HUMAN,
                $request->user()?->id
            );

            return $this->successResponse([
                'prompt' => new PresetPromptResource($prompt),
            ]);

        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to update prompt');
        }
    }

    /**
     * DELETE /admin/presets/{id}/prompts/{promptId}
     *
     * Delete a prompt (forbidden if it's the last one).
     */
    public function destroy(int $id, int $promptId): JsonResponse
    {
        try {
            $preset = $this->presetService->findById($id);

            if (!$preset) {
                return $this->errorResponse('Preset not found', 404);
            }

            $this->promptService->delete($preset, $promptId);

            return $this->successResponse([
                'active_prompt_id' => $preset->fresh()->active_prompt_id,
            ]);

        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete prompt');
        }
    }

    /**
     * PATCH /admin/presets/{id}/prompts/{promptId}/activate
     *
     * Set a prompt as active.
     */
    public function activate(int $id, int $promptId): JsonResponse
    {
        try {
            $preset = $this->presetService->findById($id);

            if (!$preset) {
                return $this->errorResponse('Preset not found', 404);
            }

            $this->promptService->setActive($preset, $promptId);

            return $this->successResponse([
                'active_prompt_id' => $promptId,
            ]);

        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to activate prompt');
        }
    }

    /**
     * POST /admin/presets/{id}/prompts/{promptId}/duplicate
     *
     * Duplicate a prompt.
     */
    public function duplicate(int $id, int $promptId): JsonResponse
    {
        try {
            $preset = $this->presetService->findById($id);

            if (!$preset) {
                return $this->errorResponse('Preset not found', 404);
            }

            $newPrompt = $this->promptService->duplicate($preset, $promptId);

            return $this->successResponse([
                'prompt' => new PresetPromptResource($newPrompt),
            ], 201);

        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to duplicate prompt');
        }
    }

    // ─── Versioning ──────────────────────────────────────────────────────────

    /**
     * GET /admin/presets/{id}/prompts/{promptId}/versions
     *
     * List the version history of a prompt, newest first.
     */
    public function versions(int $id, int $promptId): JsonResponse
    {
        try {
            $preset = $this->presetService->findById($id);

            if (!$preset) {
                return $this->errorResponse('Preset not found', 404);
            }

            $versions = $this->promptService->getHistory($preset, $promptId);

            // Mark which version matches current head content, so the UI can
            // show a "current" badge without a second round-trip. Computed here
            // (not in the Resource) because a version doesn't know the head.
            $prompt = $this->promptService->findById($preset, $promptId);
            $currentContent = $prompt?->getContent();

            $payload = PresetPromptVersionResource::collection($versions)
                ->toArray(request());

            foreach ($payload as &$row) {
                $row['is_current'] = $currentContent !== null
                    && $row['content'] === $currentContent;
            }
            unset($row);

            return $this->successResponse([
                'versions' => $payload,
            ]);

        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve version history');
        }
    }

    /**
     * GET /admin/presets/{id}/prompts/{promptId}/versions/{version}
     *
     * Fetch a single version's full content (for diff view / preview).
     */
    public function version(int $id, int $promptId, int $version): JsonResponse
    {
        try {
            $preset = $this->presetService->findById($id);

            if (!$preset) {
                return $this->errorResponse('Preset not found', 404);
            }

            $found = $this->promptService->getVersion($preset, $promptId, $version);

            if (!$found) {
                return $this->errorResponse('Version not found', 404);
            }

            return $this->successResponse([
                'version' => new PresetPromptVersionResource($found),
            ]);

        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve version');
        }
    }

    /**
     * POST /admin/presets/{id}/prompts/{promptId}/versions/{version}/revert
     *
     * Revert a prompt to an earlier version. Non-destructive: appends a new
     * version recording the revert. Attributed to the acting human user.
     */
    public function revert(int $id, int $promptId, int $version): JsonResponse
    {
        try {
            $preset = $this->presetService->findById($id);

            if (!$preset) {
                return $this->errorResponse('Preset not found', 404);
            }

            $prompt = $this->promptService->revertToVersion(
                $preset,
                $promptId,
                $version,
                PresetPromptVersion::BY_HUMAN,
                request()->user()?->id
            );

            return $this->successResponse([
                'prompt' => new PresetPromptResource($prompt),
            ]);

        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to revert prompt');
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    protected function successResponse($data = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    protected function errorResponse(string $message, int $status = 500): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}

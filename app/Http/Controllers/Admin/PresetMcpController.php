<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Mcp\McpClientInterface;
use App\Contracts\Agent\Mcp\McpServerRepositoryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Mcp\McpServerActionRequest;
use App\Http\Requests\Admin\Mcp\StoreMcpServerRequest;
use App\Models\McpServer;
use Illuminate\Http\JsonResponse;
use Psr\Log\LoggerInterface;

class PresetMcpController extends Controller
{
    public function __construct(
        protected PresetServiceInterface $presetService,
        protected McpServerRepositoryInterface $serverRepository,
        protected McpClientInterface $mcpClient,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * GET /admin/presets/{presetId}/mcp
     */
    public function index(int $presetId): JsonResponse
    {
        $preset = $this->presetService->findById($presetId);

        if (!$preset) {
            return $this->errorResponse('Preset not found', 404);
        }

        $servers = $this->serverRepository->allForPreset($preset, enabledOnly: false);

        return $this->successResponse([
            'servers' => array_map(fn ($s) => $this->serializeServer($s), $servers),
        ]);
    }

    /**
     * POST /admin/presets/{presetId}/mcp
     */
    public function store(StoreMcpServerRequest $request, int $presetId): JsonResponse
    {
        $preset = $request->resolvePreset();

        if (!$preset) {
            return $this->errorResponse('Preset not found', 404);
        }

        $server = $this->serverRepository->create(
            $preset,
            $request->validated(),
            addedByAgent: false,
        );

        return $this->successResponse([
            'server' => $this->serializeServer($server),
        ]);
    }

    /**
     * DELETE /admin/presets/{presetId}/mcp/{serverId}
     */
    public function destroy(McpServerActionRequest $request): JsonResponse
    {
        $this->serverRepository->delete($request->getServer());

        return $this->successResponse(['deleted' => true]);
    }

    /**
     * PATCH /admin/presets/{presetId}/mcp/{serverId}/toggle
     */
    public function toggle(McpServerActionRequest $request): JsonResponse
    {
        $server = $request->getServer();
        $server->update(['is_enabled' => !$server->is_enabled]);

        return $this->successResponse([
            'server' => $this->serializeServer($server->fresh()),
        ]);
    }

    /**
     * POST /admin/presets/{presetId}/mcp/{serverId}/ping
     */
    public function ping(McpServerActionRequest $request): JsonResponse
    {
        $server = $request->getServer();

        try {
            $tools = $this->mcpClient->listTools($server);
            $this->serverRepository->cacheTools($server, $tools);
            $this->serverRepository->updateHealth($server, 'ok');

            return $this->successResponse([
                'server' => $this->serializeServer($server->fresh()),
                'tools'  => $tools,
            ]);
        } catch (\Throwable $e) {
            $this->serverRepository->updateHealth($server, 'error', $e->getMessage());

            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function serializeServer(McpServer $server): array
    {
        return [
            'id'              => $server->getId(),
            'name'            => $server->getName(),
            'server_key'      => $server->getKey(),
            'url'             => $server->getUrl(),
            'is_enabled'      => $server->isEnabled(),
            'added_by_agent'  => $server->isAddedByAgent(),
            'health_status'   => $server->getHealthStatus(),
            'last_error'      => $server->last_error,
            'last_checked_at' => $server->last_checked_at?->toISOString(),
            'tools_count'     => count($server->getCachedTools()),
            'tools'           => $server->getCachedTools(),
            'tools_cached_at' => $server->tools_cached_at?->toISOString(),
        ];
    }

    protected function successResponse($data = null): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    protected function errorResponse(string $message, int $status = 500): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}

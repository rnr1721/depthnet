<?php

namespace App\Http\Requests\Admin\Mcp;

use App\Contracts\Agent\Mcp\McpServerRepositoryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Models\AiPreset;
use App\Models\McpServer;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Base request for actions targeting a specific MCP server.
 *
 * Resolves and validates both the preset and the server from route parameters.
 * Used by toggle, ping, and destroy actions.
 *
 * Routes:
 *   PATCH  /admin/presets/{presetId}/mcp/{serverId}/toggle
 *   POST   /admin/presets/{presetId}/mcp/{serverId}/ping
 *   DELETE /admin/presets/{presetId}/mcp/{serverId}
 */
class McpServerActionRequest extends FormRequest
{
    protected ?AiPreset $resolvedPreset = null;
    protected ?McpServer $resolvedServer = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    /**
     * Validate that both the preset and server exist.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->resolveEntities();
        });
    }

    /**
     * Get the validated preset model.
     *
     * @throws NotFoundHttpException
     */
    public function getPreset(): AiPreset
    {
        $this->resolveEntities();

        if (!$this->resolvedPreset) {
            throw new NotFoundHttpException('Preset not found');
        }

        return $this->resolvedPreset;
    }

    /**
     * Get the validated MCP server model.
     *
     * @throws NotFoundHttpException
     */
    public function getServer(): McpServer
    {
        $this->resolveEntities();

        if (!$this->resolvedServer) {
            throw new NotFoundHttpException('Server not found');
        }

        return $this->resolvedServer;
    }

    /**
     * Resolve preset and server from route parameters (cached).
     */
    protected function resolveEntities(): void
    {
        if ($this->resolvedPreset !== null) {
            return;
        }

        $presetId = (int) $this->route('presetId');
        $serverId = (int) $this->route('serverId');

        $this->resolvedPreset = app(PresetServiceInterface::class)->findById($presetId);

        if (!$this->resolvedPreset) {
            return;
        }

        $servers = app(McpServerRepositoryInterface::class)
            ->allForPreset($this->resolvedPreset, enabledOnly: false);

        $this->resolvedServer = collect($servers)->firstWhere('id', $serverId);
    }
}

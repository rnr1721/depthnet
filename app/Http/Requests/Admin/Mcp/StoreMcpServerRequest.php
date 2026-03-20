<?php

namespace App\Http\Requests\Admin\Mcp;

use App\Contracts\Agent\Mcp\McpServerRepositoryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and authorizes requests to create a new MCP server.
 *
 * Route: POST /admin/presets/{presetId}/mcp
 */
class StoreMcpServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:100'],
            'server_key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/'],
            'url'        => ['required', 'url', 'max:2048'],
            'headers'    => ['nullable', 'array'],
            'headers.*'  => ['string', 'max:4096'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'server_key.regex' => 'Server key must contain only lowercase letters, numbers, hyphens and underscores.',
        ];
    }

    /**
     * Additional validation: check server_key uniqueness within the preset.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $preset = $this->resolvePreset();
            if (!$preset) {
                return; // Controller handles missing preset
            }

            $repository = app(McpServerRepositoryInterface::class);

            if ($repository->findByKey($preset, $this->input('server_key'))) {
                $validator->errors()->add(
                    'server_key',
                    "Server key '{$this->input('server_key')}' already exists for this preset."
                );
            }
        });
    }

    /**
     * Resolve the preset from the route parameter.
     */
    public function resolvePreset(): ?\App\Models\AiPreset
    {
        $presetId = (int) $this->route('presetId');

        return app(PresetServiceInterface::class)->findById($presetId);
    }
}

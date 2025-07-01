<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PresetResource extends JsonResource
{
    /**
     * Transform the resource into an array
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'engine_name' => $this->engine_name,
            'system_prompt' => $this->system_prompt,
            'plugins_disabled' => $this->plugins_disabled,
            'engine_config' => $this->engine_config,
            'loop_interval' => $this->loop_interval,
            'max_context_limit' => $this->max_context_limit,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),

            'engine_display_name' => $this->when(
                isset($this->engine_display_name),
                $this->engine_display_name
            ),
        ];
    }
}

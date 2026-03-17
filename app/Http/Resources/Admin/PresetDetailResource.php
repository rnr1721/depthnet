<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;

class PresetDetailResource extends PresetResource
{
    /**
     * Transform the resource into an array with additional details
     *
     * @param Request $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'engine_config_fields' => $this->when(
                isset($this->engine_config_fields),
                $this->engine_config_fields
            ),

            // Prompts for this preset, ordered by creation date.
            // is_active marks the currently active prompt (matches active_prompt_id).
            'prompts' => $this->prompts->map(fn ($p) => [
                'id'          => $p->id,
                'code'        => $p->code,
                'content'     => $p->content,
                'description' => $p->description,
                'is_active'   => $p->id === $this->active_prompt_id,
            ]),
        ]);
    }
}

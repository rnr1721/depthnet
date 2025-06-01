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
        ]);
    }
}

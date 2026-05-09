<?php

namespace App\Http\Requests\Admin\Ontology;

class StoreOntologyNodeRequest extends BaseOntologyRequest
{
    public function rules(): array
    {
        return array_merge($this->presetRules(), [
            'canonical_name' => 'sometimes|string|max:255',
            'class'          => 'required|string|max:50',
            'aliases'        => 'nullable|array',
            'aliases.*'      => 'string|max:100',
            'weight'         => 'sometimes|numeric|min:0',
        ]);
    }
}

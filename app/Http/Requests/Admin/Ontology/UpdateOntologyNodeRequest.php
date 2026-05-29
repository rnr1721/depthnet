<?php

namespace App\Http\Requests\Admin\Ontology;

class UpdateOntologyNodeRequest extends BaseOntologyRequest
{
    public function rules(): array
    {
        return array_merge($this->presetRules(), [
            'canonical_name' => 'required|string|max:255',
            'class'    => 'required|string|max:50',
            'aliases'  => 'nullable|array',
            'aliases.*' => 'string|max:100',
            'weight'         => 'required|numeric|min:0',
        ]);
    }
}

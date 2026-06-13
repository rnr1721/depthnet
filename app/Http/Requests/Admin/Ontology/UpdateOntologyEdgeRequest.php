<?php

namespace App\Http\Requests\Admin\Ontology;

class UpdateOntologyEdgeRequest extends BaseOntologyRequest
{
    public function rules(): array
    {
        return array_merge($this->presetRules(), [
            'relation_type' => 'required|string|max:100',
            'weight'        => 'nullable|numeric|min:0|max:100',
        ]);
    }
}

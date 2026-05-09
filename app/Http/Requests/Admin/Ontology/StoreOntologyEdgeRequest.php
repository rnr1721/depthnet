<?php

namespace App\Http\Requests\Admin\Ontology;

class StoreOntologyEdgeRequest extends BaseOntologyRequest
{
    public function rules(): array
    {
        return array_merge($this->presetRules(), [
            'source'        => 'required|string|max:255',
            'target'        => 'required|string|max:255',
            'relation_type' => 'required|string|max:100',
            'weight'        => 'nullable|numeric|min:0|max:100',
        ]);
    }
}

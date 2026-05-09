<?php

namespace App\Http\Requests\Admin\Ontology;

class UpdateOntologyNodeRequest extends BaseOntologyRequest
{
    public function rules(): array
    {
        return array_merge($this->presetRules(), [
            'class'    => 'required|string|max:50',
            'aliases'  => 'nullable|array',
            'aliases.*' => 'string|max:100',
        ]);
    }
}

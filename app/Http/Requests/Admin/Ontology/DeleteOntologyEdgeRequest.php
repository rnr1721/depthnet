<?php

namespace App\Http\Requests\Admin\Ontology;

class DeleteOntologyEdgeRequest extends BaseOntologyRequest
{
    public function rules(): array
    {
        return $this->presetRules();
    }
}

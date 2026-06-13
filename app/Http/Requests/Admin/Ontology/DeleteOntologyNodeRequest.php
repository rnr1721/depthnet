<?php

namespace App\Http\Requests\Admin\Ontology;

class DeleteOntologyNodeRequest extends BaseOntologyRequest
{
    public function rules(): array
    {
        return $this->presetRules();
    }
}

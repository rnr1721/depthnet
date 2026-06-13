<?php

namespace App\Http\Requests\Admin\Ontology;

class OntologyPresetRequest extends BaseOntologyRequest
{
    public function rules(): array
    {
        return $this->presetRules();
    }
}

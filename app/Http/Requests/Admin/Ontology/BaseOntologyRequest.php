<?php

namespace App\Http\Requests\Admin\Ontology;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseOntologyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function presetRules(): array
    {
        return ['preset_id' => 'required|exists:ai_presets,id'];
    }
}

<?php

namespace App\Http\Requests\Admin\VectorMemory;

/**
 * Request validation for clearing all vector memories
 */
class ClearVectorMemoryRequest extends BaseVectorMemoryRequest
{
    /**
     * Get the validation rules that apply to the request
     */
    public function rules(): array
    {
        return $this->presetValidationRules();
    }

    /**
     * Additional validation to ensure user really wants to clear all
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // TODO: additional validation?
        });
    }
}

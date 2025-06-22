<?php

namespace App\Http\Requests\Admin\VectorMemory;

/**
 * Request validation for updating vector memory importance
 */
class UpdateImportanceRequest extends BaseVectorMemoryRequest
{
    /**
     * Get the validation rules that apply to the request
     */
    public function rules(): array
    {
        return array_merge($this->presetValidationRules(), [
            'importance' => 'required|numeric|min:0.1|max:5.0',
        ]);
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'importance.required' => 'Importance value is required.',
            'importance.numeric' => 'Importance must be a number.',
            'importance.min' => 'Importance cannot be less than 0.1.',
            'importance.max' => 'Importance cannot be greater than 5.0.',
        ]);
    }

    /**
     * Get normalized importance value
     */
    public function getImportance(): float
    {
        return (float) $this->validated('importance');
    }
}

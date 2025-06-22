<?php

namespace App\Http\Requests\Admin\VectorMemory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request class for vector memory operations
 * Contains common validation rules and authorization logic
 */
abstract class BaseVectorMemoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get common validation rules for preset_id
     */
    protected function presetValidationRules(): array
    {
        return [
            'preset_id' => 'required|exists:ai_presets,id',
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'preset_id.required' => 'Preset is required.',
            'preset_id.exists' => 'Selected preset does not exist.',
        ];
    }
}

<?php

namespace App\Http\Requests\Admin\Plugin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Copy Plugin Configurations Request
 *
 * Validates copying plugin configurations between presets
 * (This is the ONLY validation that actually exists in the original controller)
 */
class CopyPluginConfigurationsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request
     *
     * These are the exact same rules from the original controller
     */
    public function rules(): array
    {
        return [
            'from_preset_id' => 'required|integer|exists:ai_presets,id',
            'to_preset_id' => 'required|integer|exists:ai_presets,id|different:from_preset_id',
        ];
    }

    /**
     * Get custom messages for validator errors
     */
    public function messages(): array
    {
        return [
            'from_preset_id.required' => 'Source preset is required.',
            'from_preset_id.exists' => 'Source preset does not exist.',
            'to_preset_id.required' => 'Target preset is required.',
            'to_preset_id.exists' => 'Target preset does not exist.',
            'to_preset_id.different' => 'Target preset must be different from source preset.',
        ];
    }
}

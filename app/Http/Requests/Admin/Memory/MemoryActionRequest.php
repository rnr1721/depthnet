<?php

namespace App\Http\Requests\Admin\Memory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request for simple memory actions (delete, clear, export, stats)
 * that only require preset_id validation
 */
class MemoryActionRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preset_id' => 'required|exists:ai_presets,id',
        ];
    }

    /**
     * Get custom messages for validator errors
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'preset_id.required' => 'Preset is required.',
            'preset_id.exists' => 'Selected preset does not exist.',
        ];
    }

    /**
     * Get custom attributes for validator errors
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'preset_id' => 'preset',
        ];
    }
}

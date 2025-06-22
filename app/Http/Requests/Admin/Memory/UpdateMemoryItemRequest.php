<?php

namespace App\Http\Requests\Admin\Memory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request for updating an existing memory item
 */
class UpdateMemoryItemRequest extends FormRequest
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
            'content' => 'required|string|min:1|max:2000',
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
            'content.required' => 'Content is required.',
            'content.min' => 'Content must be at least 1 character long.',
            'content.max' => 'Content may not be greater than 2000 characters.',
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
            'content' => 'memory content',
        ];
    }
}

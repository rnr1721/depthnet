<?php

namespace App\Http\Requests\Admin\Memory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request for searching memory items
 */
class SearchMemoryRequest extends FormRequest
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
            'search_term' => 'required|string|min:1|max:255',
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
            'search_term.required' => 'Search term is required.',
            'search_term.min' => 'Search term must be at least 1 character long.',
            'search_term.max' => 'Search term may not be greater than 255 characters.',
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
            'search_term' => 'search term',
        ];
    }
}

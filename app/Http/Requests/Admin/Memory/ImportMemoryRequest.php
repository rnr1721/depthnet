<?php

namespace App\Http\Requests\Admin\Memory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request for importing memory content
 */
class ImportMemoryRequest extends FormRequest
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
            'file' => 'nullable|file|mimes:txt|max:1024', // 1MB max
            'content' => 'nullable|string|max:10000',
            'replace_existing' => 'boolean',
        ];
    }

    /**
     * Configure the validator instance
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Must have either file or content
            if (!$this->hasFile('file') && !$this->filled('content')) {
                $validator->errors()->add('content', 'Please provide either a file or content to import.');
            }
        });
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
            'file.file' => 'The uploaded file is not valid.',
            'file.mimes' => 'The file must be a text file (.txt).',
            'file.max' => 'The file may not be greater than 1MB.',
            'content.max' => 'Content may not be greater than 10000 characters.',
            'replace_existing.boolean' => 'Replace existing must be true or false.',
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
            'file' => 'import file',
            'content' => 'import content',
            'replace_existing' => 'replace existing option',
        ];
    }
}

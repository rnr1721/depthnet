<?php

namespace App\Http\Requests\Admin\VectorMemory;

/**
 * Request validation for importing vector memories
 */
class ImportVectorMemoryRequest extends BaseVectorMemoryRequest
{
    /**
     * Get the validation rules that apply to the request
     */
    public function rules(): array
    {
        return array_merge($this->presetValidationRules(), [
            'file' => 'nullable|file|mimes:json,txt|max:2048', // 2MB max
            'content' => 'nullable|string|max:20000',
            'replace_existing' => 'boolean',
        ]);
    }

    /**
     * Configure the validator instance
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Must have either file or content
            if (!$this->hasFile('file') && !$this->filled('content')) {
                $validator->errors()->add(
                    'import_data',
                    'Please provide either a file or content to import.'
                );
            }
        });
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'file.mimes' => 'File must be a JSON or TXT file.',
            'file.max' => 'File size cannot exceed 2MB.',
            'content.max' => 'Content cannot exceed 20,000 characters.',
        ]);
    }

    /**
     * Get import content from file or direct input
     */
    public function getImportContent(): array
    {
        if ($this->hasFile('file')) {
            $content = file_get_contents($this->file('file')->path());
            $isJson = $this->file('file')->getClientOriginalExtension() === 'json';
        } else {
            $content = $this->input('content');
            $isJson = false;
        }

        if (empty(trim($content))) {
            throw new \InvalidArgumentException('Content is empty or contains no valid data.');
        }

        return [
            'content' => $content,
            'is_json' => $isJson,
            'replace_existing' => $this->boolean('replace_existing')
        ];
    }
}

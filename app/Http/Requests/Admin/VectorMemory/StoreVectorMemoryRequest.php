<?php

namespace App\Http\Requests\Admin\VectorMemory;

/**
 * Request validation for storing new vector memory
 */
class StoreVectorMemoryRequest extends BaseVectorMemoryRequest
{
    /**
     * Get the validation rules that apply to the request
     */
    public function rules(): array
    {
        return array_merge($this->presetValidationRules(), [
            'content' => 'required|string|min:1|max:5000',
        ]);
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'content.required' => 'Content is required.',
            'content.min' => 'Content must not be empty.',
            'content.max' => 'Content cannot exceed 5000 characters.',
        ]);
    }

    /**
     * Get validated content with UTF-8 safety
     */
    public function getValidatedContent(): string
    {
        $content = $this->validated('content');

        // Ensure valid UTF-8
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', mb_detect_encoding($content));
        }

        return trim($content);
    }
}

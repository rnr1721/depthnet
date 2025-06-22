<?php

namespace App\Http\Requests\Admin\VectorMemory;

/**
 * Request validation for searching vector memories
 */
class SearchVectorMemoryRequest extends BaseVectorMemoryRequest
{
    /**
     * Get the validation rules that apply to the request
     */
    public function rules(): array
    {
        return array_merge($this->presetValidationRules(), [
            'query' => 'required|string|min:1|max:255',
        ]);
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'query.required' => 'Search query is required.',
            'query.min' => 'Search query cannot be empty.',
            'query.max' => 'Search query cannot exceed 255 characters.',
        ]);
    }

    /**
     * Get sanitized search query
     */
    public function getSearchQuery(): string
    {
        $query = $this->validated('query');

        if (!mb_check_encoding($query, 'UTF-8')) {
            $query = mb_convert_encoding($query, 'UTF-8', mb_detect_encoding($query));
        }

        return trim($query);
    }
}

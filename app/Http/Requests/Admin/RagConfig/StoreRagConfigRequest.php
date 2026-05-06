<?php

namespace App\Http\Requests\Admin\RagConfig;

use Illuminate\Foundation\Http\FormRequest;

class StoreRagConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rag_preset_id'              => 'required|integer|exists:ai_presets,id',
            'sources'                    => 'nullable|array',
            'sources.*'                  => 'string|in:vector_memory,journal,skills,persons,files',
            'rag_mode'                   => 'sometimes|in:flat,associative',
            'rag_engine'                 => 'sometimes|in:tfidf,embedding',
            'rag_context_limit'          => 'sometimes|integer|min:1|max:50',
            'rag_results'                => 'sometimes|integer|min:1|max:50',
            'rag_journal_limit'          => 'sometimes|integer|min:0|max:50',
            'rag_skills_limit'           => 'sometimes|integer|min:0|max:50',
            'rag_content_limit'          => 'sometimes|integer|min:50|max:5000',
            'rag_journal_context_window' => 'sometimes|integer|min:0|max:10',
            'rag_relative_dates'         => 'sometimes|boolean',
        ];
    }
}

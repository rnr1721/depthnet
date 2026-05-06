<?php

namespace App\Http\Requests\Admin\File;

use Illuminate\Foundation\Http\FormRequest;

class SearchFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id' => ['required', 'integer', 'exists:ai_presets,id'],
            'query'     => ['required', 'string', 'max:500'],
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->validated('preset_id');
    }
    public function getQuery(): string
    {
        return $this->validated('query');
    }
}

<?php

namespace App\Http\Requests\Admin\File;

use Illuminate\Foundation\Http\FormRequest;

class DestroyFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id' => ['required', 'integer', 'exists:ai_presets,id'],
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->validated('preset_id');
    }
}

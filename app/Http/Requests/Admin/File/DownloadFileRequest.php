<?php

namespace App\Http\Requests\Admin\File;

use Illuminate\Foundation\Http\FormRequest;

class DownloadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id' => ['nullable', 'integer', 'exists:ai_presets,id'],
        ];
    }

    public function getPresetId(): ?int
    {
        $id = $this->input('preset_id');
        return $id ? (int) $id : null;
    }
}

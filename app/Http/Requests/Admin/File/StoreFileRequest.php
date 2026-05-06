<?php

namespace App\Http\Requests\Admin\File;

use Illuminate\Foundation\Http\FormRequest;

class StoreFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id'    => ['required', 'integer', 'exists:ai_presets,id'],
            'file'         => ['required', 'file', 'max:51200'],
            'driver'       => ['sometimes', 'in:laravel,sandbox'],
            'scope'        => ['sometimes', 'in:private,global'],
            'project_slug' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->validated('preset_id');
    }
    public function getDriver(): string
    {
        return $this->input('driver', 'laravel');
    }
    public function getScope(): string
    {
        return $this->input('scope', 'private');
    }
    public function getProjectSlug(): ?string
    {
        return $this->input('project_slug');
    }
}

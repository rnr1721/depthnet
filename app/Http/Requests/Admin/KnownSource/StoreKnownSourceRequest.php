<?php

namespace App\Http\Requests\Admin\KnownSource;

use Illuminate\Foundation\Http\FormRequest;

class StoreKnownSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id'     => ['required', 'integer', 'exists:ai_presets,id'],
            'source_name'   => ['required', 'string', 'max:100'],
            'label'         => ['required', 'string', 'max:150'],
            'description'   => ['nullable', 'string', 'max:300'],
            'default_value' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->input('preset_id');
    }
    public function getSourceName(): string
    {
        return $this->input('source_name');
    }
    public function getLabel(): string
    {
        return $this->input('label');
    }
    public function getDescription(): ?string
    {
        return $this->input('description');
    }
    public function getDefaultValue(): ?string
    {
        return $this->input('default_value');
    }
}

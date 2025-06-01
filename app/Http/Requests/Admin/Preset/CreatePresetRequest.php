<?php

namespace App\Http\Requests\Admin\Preset;

use Illuminate\Foundation\Http\FormRequest;

class CreatePresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:ai_presets,name'],
            'system_prompt' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'dopamine_level' => ['nullable','integer'],
            'description' => ['nullable', 'string', 'max:1000'],
            'engine_name' => ['required', 'string', 'max:100'],
            'plugins_disabled' => ['nullable','string','max:255'],
            'engine_config' => ['required', 'array'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A preset with this name already exists.',
            'engine_config.required' => 'Engine configuration is required.',
        ];
    }
}

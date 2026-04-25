<?php

namespace App\Http\Requests\Admin\Preset;

use Illuminate\Foundation\Http\FormRequest;

class CreatePresetPromptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presetId = $this->route('id');

        return [
            'code'          => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-zA-Z0-9_\-]+$/',
                // Uniqueness within the preset is checked in the service,
                // but you can add it here for a quick fail:
                "unique:preset_prompts,code,NULL,id,preset_id,{$presetId}",
            ],
            'content'       => ['required', 'string', 'max:20000'],
            'description'   => ['nullable', 'string', 'max:500'],
            'set_as_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex'  => 'Prompt code may only contain letters, numbers, underscores and hyphens.',
            'code.unique' => 'A prompt with this code already exists for this preset.',
        ];
    }
}

<?php

namespace App\Http\Requests\Admin\Preset;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePresetPromptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presetId  = $this->route('id');
        $promptId  = $this->route('promptId');

        return [
            'code'        => [
                'sometimes',
                'string',
                'max:50',
                'regex:/^[a-zA-Z0-9_\-]+$/',
                "unique:preset_prompts,code,{$promptId},id,preset_id,{$presetId}",
            ],
            'content'     => ['sometimes', 'string', 'max:10000'],
            'description' => ['nullable', 'string', 'max:500'],
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

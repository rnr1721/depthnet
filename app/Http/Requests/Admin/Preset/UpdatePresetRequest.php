<?php

namespace App\Http\Requests\Admin\Preset;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presetId = $this->route('id');

        return [
            'name' => ['string', 'max:255', "unique:ai_presets,name,{$presetId}"],
            'system_prompt' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'dopamine_level' => ['nullable','integer','min:1'],
            'plugins_disabled' => ['nullable','string','max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'engine_name' => ['string', 'max:100'],
            'engine_config' => ['array'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ];
    }
}

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
            'plugins_disabled' => ['nullable','string','max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'engine_name' => ['string', 'max:100'],
            'engine_config' => ['array'],
            'loop_interval' => ['required','integer','min:4','max:30'],
            'max_context_limit' => ['required','integer','min:0','max:50'],
            'agent_result_mode' => ['required','string'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ];
    }
}

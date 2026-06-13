<?php

namespace App\Http\Requests\Admin\InnerVoiceConfig;

use Illuminate\Foundation\Http\FormRequest;

class StoreInnerVoiceConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'voice_preset_id' => 'required|integer|exists:ai_presets,id',
            'context_limit'   => 'sometimes|integer|min:1|max:100',
            'label'           => 'sometimes|nullable|string|max:100',
            'is_enabled'      => 'sometimes|boolean',
        ];
    }
}

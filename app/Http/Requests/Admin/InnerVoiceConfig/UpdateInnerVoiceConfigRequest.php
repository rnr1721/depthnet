<?php

namespace App\Http\Requests\Admin\InnerVoiceConfig;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInnerVoiceConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'context_limit' => 'sometimes|integer|min:1|max:100',
            'label'         => 'sometimes|nullable|string|max:100',
            'is_enabled'    => 'sometimes|boolean',
        ];
    }
}

<?php

namespace App\Http\Requests\Admin\InnerVoiceConfig;

use Illuminate\Foundation\Http\FormRequest;

class ReorderInnerVoiceConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ];
    }
}

<?php

namespace App\Http\Requests\Admin\Preset;

use Illuminate\Foundation\Http\FormRequest;

class ValidatePresetConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'engine_name' => ['required', 'string'],
            'engine_config' => ['required', 'array']
        ];
    }
}

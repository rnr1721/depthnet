<?php

namespace App\Http\Requests\Admin\Capabilities;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCapabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'driver'    => ['required', 'string'],
            'config'    => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ];
    }
}

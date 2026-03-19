<?php

namespace App\Http\Requests\ApiKey;

use Illuminate\Foundation\Http\FormRequest;

class CreateApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is already behind auth middleware
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please provide a name for this key (e.g. "Production bot").',
            'name.max'      => 'Key name must not exceed 64 characters.',
        ];
    }
}

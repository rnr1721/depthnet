<?php

namespace App\Http\Requests\Api\Chat;

use Illuminate\Foundation\Http\FormRequest;

class PoolInputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source'   => 'required|string|max:128',
            'content'  => 'required|string|max:32000',
            'dispatch' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'source.required' => 'Source name is required (e.g. "Weather sensor", "Inner voice").',
        ];
    }
}

<?php

namespace App\Http\Requests\Admin\Engine;

use Illuminate\Foundation\Http\FormRequest;

class TestEngineWithConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'config' => ['required', 'array'],
            'config.*' => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'config.required' => 'Engine configuration is required for testing.',
            'config.array' => 'Engine configuration must be a valid configuration object.',
        ];
    }

    /**
     * Get the validated config data
     */
    public function getConfig(): array
    {
        return $this->validated()['config'];
    }
}

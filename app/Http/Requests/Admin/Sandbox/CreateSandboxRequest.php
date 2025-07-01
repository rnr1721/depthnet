<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Sandbox;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create sandbox request validation
 */
class CreateSandboxRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request
     */
    public function rules(): array
    {
        return [
            'type' => 'nullable|string',
            'name' => [
                'nullable',
                'string',
                'max:64',
                'regex:/^[a-zA-Z0-9\-_]+$/'
            ],
            'ports' => [
                'nullable',
                'string',
                'regex:/^[1-9][0-9]{0,4}(?:,[1-9][0-9]{0,4})*$/',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $ports = explode(',', $value);
                        foreach ($ports as $port) {
                            if ((int)$port > 65535) {
                                $fail('Port numbers must be between 1 and 65535');
                            }
                        }
                    }
                }
            ]
        ];
    }

    /**
     * Get custom messages for validator errors
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Sandbox name can only contain letters, numbers, hyphens and underscores',
            'name.max' => 'Sandbox name cannot be longer than 64 characters',
            'type.in' => 'Invalid sandbox type. Allowed types: ubuntu-full, minimal, python, node',
            'ports.regex' => 'Ports must be numbers separated by commas (e.g., 80,443,3000)'
        ];
    }
}

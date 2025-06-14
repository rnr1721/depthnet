<?php

namespace App\Http\Requests\Admin\Plugin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for updating plugin configuration
 *
 * Validates plugin configuration data with flexible rules
 */
class UpdatePluginConfigRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'api_key' => ['nullable', 'string', 'max:500'],
            'api_url' => ['nullable', 'string', 'max:500'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:300'],
            'max_retries' => ['nullable', 'integer', 'min:0', 'max:10'],
            'debug_mode' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
            '*' => ['nullable'],
        ];
    }

    /**
     * Get custom messages for validator errors
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_url.max' => 'The API URL cannot exceed 500 characters.',
            'timeout.min' => 'Timeout must be at least 1 second.',
            'timeout.max' => 'Timeout cannot exceed 300 seconds.',
            'max_retries.max' => 'Maximum retries cannot exceed 10.',
        ];
    }

    /**
     * Prepare the data for validation
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Convert string boolean values to actual booleans
        $booleanFields = ['debug_mode', 'enabled'];

        foreach ($booleanFields as $field) {
            if ($this->has($field)) {
                $this->merge([
                    $field => filter_var($this->input($field), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ]);
            }
        }

        // Ensure numeric fields are integers
        $intFields = ['timeout', 'max_retries'];

        foreach ($intFields as $field) {
            if ($this->has($field) && $this->input($field) !== null) {
                $this->merge([$field => (int) $this->input($field)]);
            }
        }
    }
}

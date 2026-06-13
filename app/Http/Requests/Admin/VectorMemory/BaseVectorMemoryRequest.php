<?php

namespace App\Http\Requests\Admin\VectorMemory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request class for vector memory operations
 * Contains common validation rules and authorization logic
 */
abstract class BaseVectorMemoryRequest extends FormRequest
{
    /**
     * Max length of a domain name. Mirrors VectorMemoryService::DOMAIN_MAX_LENGTH.
     */
    protected const DOMAIN_MAX_LENGTH = 64;

    /**
     * Characters not allowed in a domain name. Mirrors VectorMemoryService::DOMAIN_FORBIDDEN_CHARS.
     */
    protected const DOMAIN_FORBIDDEN_CHARS = '|,:"\'';

    /**
     * Determine if the user is authorized to make this request
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get common validation rules for preset_id
     */
    protected function presetValidationRules(): array
    {
        return [
            'preset_id' => 'required|exists:ai_presets,id',
        ];
    }

    /**
     * Common validation rules for an optional domain name field.
     *
     * The field is allowed to be missing or empty, in which case the caller
     * should fall back to the plugin's default_domain. Maximum length and
     * forbidden characters mirror the service-level constants so we fail
     * fast at the HTTP boundary rather than inside storeVectorMemory().
     */
    protected function optionalDomainRules(string $field = 'domain'): array
    {
        return [
            $field => [
                'nullable',
                'string',
                'max:' . self::DOMAIN_MAX_LENGTH,
                'regex:/^[^' . preg_quote(self::DOMAIN_FORBIDDEN_CHARS, '/') . '\s][^' . preg_quote(self::DOMAIN_FORBIDDEN_CHARS, '/') . ']*$/u',
            ],
        ];
    }

    /**
     * Required domain rules (e.g. for purge).
     */
    protected function requiredDomainRules(string $field = 'domain'): array
    {
        return [
            $field => [
                'required',
                'string',
                'max:' . self::DOMAIN_MAX_LENGTH,
                'regex:/^[^' . preg_quote(self::DOMAIN_FORBIDDEN_CHARS, '/') . '\s][^' . preg_quote(self::DOMAIN_FORBIDDEN_CHARS, '/') . ']*$/u',
            ],
        ];
    }

    /**
     * Read a domain field, normalise it (trim + lower), and return null if empty.
     */
    protected function readDomain(string $field = 'domain'): ?string
    {
        $raw = $this->input($field);
        if (!is_string($raw)) {
            return null;
        }

        $normalized = mb_strtolower(trim($raw));
        return $normalized === '' ? null : $normalized;
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'preset_id.required' => 'Preset is required.',
            'preset_id.exists' => 'Selected preset does not exist.',
            'domain.max' => 'Domain name is too long (max ' . self::DOMAIN_MAX_LENGTH . ' characters).',
            'domain.regex' => 'Domain name contains forbidden characters or is malformed.',
            'target_domain.max' => 'Target domain name is too long.',
            'target_domain.regex' => 'Target domain name contains forbidden characters.',
        ];
    }
}

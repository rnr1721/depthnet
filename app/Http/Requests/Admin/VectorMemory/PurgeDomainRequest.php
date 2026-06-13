<?php

namespace App\Http\Requests\Admin\VectorMemory;

/**
 * Request validation for purging an entire domain from the admin UI.
 * Domain must be explicitly named — there's no "purge by similarity".
 */
class PurgeDomainRequest extends BaseVectorMemoryRequest
{
    public function rules(): array
    {
        return array_merge(
            $this->presetValidationRules(),
            $this->requiredDomainRules('domain'),
        );
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'domain.required' => 'Domain name is required to purge.',
        ]);
    }

    /**
     * Get normalised domain name (lowercased, trimmed).
     */
    public function getValidatedDomain(): string
    {
        $normalized = $this->readDomain('domain');
        return $normalized ?? ''; // required validation guarantees non-empty
    }
}

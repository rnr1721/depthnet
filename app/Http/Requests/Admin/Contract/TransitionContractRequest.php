<?php

namespace App\Http\Requests\Admin\Contract;

/**
 * Lifecycle transitions (promote/suspend/resume/revoke) all carry just a preset.
 * The target status is fixed by the route action, not user input, so there is
 * nothing form-specific to validate beyond the preset. The legality of the
 * transition itself (e.g. you can't suspend a hypothesis) is enforced by
 * ContractService::transition() against its legal-transition table — not here.
 */
class TransitionContractRequest extends BaseContractRequest
{
    public function rules(): array
    {
        return array_merge($this->presetRules(), [
            'reason' => 'nullable|string|max:255',
        ]);
    }
}

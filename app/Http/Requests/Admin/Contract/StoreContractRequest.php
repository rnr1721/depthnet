<?php

namespace App\Http\Requests\Admin\Contract;

use App\Services\Agent\Contract\ContractForm;
use App\Services\Agent\Contract\ContractStatus;
use Illuminate\Validation\Rule;

/**
 * Validates the ENVELOPE of a contract, not its deep form-specific shape.
 *
 * Cross-field invariants (form ↔ trigger coherence, vital eligibility, match-mode
 * rules) live in ContractDefinition::validate() and are enforced once, in
 * ContractService::save(). Duplicating them here would create two drifting
 * sources of truth. So this request guards only what must be structurally sound
 * before the DTO is even built: a name, a known form, and trigger/action being
 * objects rather than scalars.
 *
 * Accepts the contract either as a flat set of fields or as a single `definition`
 * object (the same canonical JSON the agent's `define` command takes). The
 * controller normalizes both into one array before handing it to fromArray().
 */
class StoreContractRequest extends BaseContractRequest
{
    public function rules(): array
    {
        return array_merge($this->presetRules(), [
            'name'          => 'required|string|max:120|regex:/^[a-zA-Z0-9_\-]+$/',
            'form'          => ['required', Rule::in(array_map(fn ($f) => $f->value, ContractForm::cases()))],
            'status'        => ['nullable', Rule::in(array_map(fn ($s) => $s->value, ContractStatus::cases()))],
            'vital'         => 'nullable|boolean',
            'trigger'       => 'required|array',
            'action'        => 'required|array',
            'action.type'   => 'required|string',
            'source'        => 'nullable|string|max:64',
            'suspend_when'  => 'nullable|string|max:120',
            'confidence'    => 'nullable|numeric|min:0|max:1',
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.regex'        => 'Name may contain only letters, numbers, underscore and hyphen.',
            'form.in'           => 'Form must be one of THR_T, THR_C, ACC, DEC.',
            'trigger.required'  => 'A trigger is required.',
            'action.required'   => 'An action is required.',
            'action.type.required' => 'The action needs a type (set_flag, inject_memo, create_goal, nudge_state).',
        ]);
    }

    /**
     * The contract definition as the canonical array shape, ready for
     * ContractDefinition::fromArray(). `match` already lives inside `trigger`.
     */
    public function getDefinitionArray(): array
    {
        $v = $this->validated();

        return array_filter([
            'name'         => $v['name'],
            'form'         => $v['form'],
            'status'       => $v['status'] ?? null,         // null → DTO defaults to hypothesis
            'vital'        => $v['vital'] ?? false,
            'trigger'      => $v['trigger'],
            'action'       => $v['action'],
            'source'       => $v['source'] ?? null,
            'suspend_when' => $v['suspend_when'] ?? null,
            'confidence'   => $v['confidence'] ?? null,
        ], fn ($x) => $x !== null);
    }
}

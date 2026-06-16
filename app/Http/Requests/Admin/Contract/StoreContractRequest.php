<?php

namespace App\Http\Requests\Admin\Contract;

use App\Services\Agent\Contract\ContractForm;
use App\Services\Agent\Contract\ContractStatus;
use Illuminate\Validation\Rule;

/**
 * Validates the ENVELOPE of a contract plus the per-type fields an action/form
 * actually needs, so a contract can't be saved in a shape that silently does
 * nothing at runtime.
 *
 * The deep cross-field invariants (vital eligibility, match-mode rules) still
 * live in ContractDefinition::validate() and run once in ContractService::save()
 * — this request does NOT duplicate those. What it adds over a bare envelope
 * check is the set of fields whose absence would make the contract a no-op:
 *
 *   - inject_memo with no `text`  → fires every rising edge, writes nothing
 *   - set_flag / create_goal with no `flag` → raises an unnamed flag (ignored)
 *   - nudge_state with no `target`/`delta` → moves nothing
 *   - THR_T with no `threshold_seconds` → never crosses
 *   - THR_C with no `threshold_count` → matches the default 0 immediately (noise)
 *   - ACC/DEC with no `target` → operates on an empty dimension
 *
 * These were previously caught only deep in the engine (or not at all — a missing
 * memo text just produced a contract that "fired" forever with no effect). Catching
 * them here turns a silent no-op into a clear, early error.
 *
 * after() (Laravel's closure-based validation hook) is used for the conditional
 * parts because the required field depends on a sibling value (action.type /
 * form), which static rules can't express cleanly.
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
            'action.type'   => ['required', 'string', Rule::in(['set_flag', 'create_goal', 'nudge_state', 'inject_memo'])],
            'source'        => 'nullable|string|max:64',
            'suspend_when'  => 'nullable|string|max:120',
            'confidence'    => 'nullable|numeric|min:0|max:1',
        ]);
    }

    /**
     * Conditional checks that depend on a sibling value (action.type / form).
     */
    public function after(): array
    {
        return [
            function ($validator) {
                $data    = $this->all();
                $action  = (array) ($data['action'] ?? []);
                $trigger = (array) ($data['trigger'] ?? []);
                $type    = $action['type'] ?? null;
                $form    = $data['form'] ?? null;

                // ── Action-specific required payload ─────────────────────────
                switch ($type) {
                    case 'inject_memo':
                        if (trim((string) ($action['text'] ?? '')) === '') {
                            $validator->errors()->add(
                                'action.text',
                                'inject_memo needs non-empty text — otherwise the contract fires but writes nothing.'
                            );
                        }
                        break;

                    case 'set_flag':
                    case 'create_goal':
                        if (trim((string) ($action['flag'] ?? '')) === '') {
                            $validator->errors()->add(
                                'action.flag',
                                "{$type} needs a flag name."
                            );
                        }
                        break;

                    case 'nudge_state':
                        if (trim((string) ($action['target'] ?? '')) === '') {
                            $validator->errors()->add('action.target', 'nudge_state needs a target dimension.');
                        }
                        if (!isset($action['delta']) || !is_numeric($action['delta'])) {
                            $validator->errors()->add('action.delta', 'nudge_state needs a numeric delta.');
                        }
                        break;
                }

                // ── Form-specific required trigger fields ────────────────────
                switch ($form) {
                    case ContractForm::THR_T->value:
                        if (!isset($trigger['threshold_seconds']) || !is_numeric($trigger['threshold_seconds'])) {
                            $validator->errors()->add('trigger.threshold_seconds', 'THR_T needs a numeric threshold_seconds.');
                        }
                        if (!isset($trigger['match']) || !is_array($trigger['match'])) {
                            $validator->errors()->add('trigger.match', 'THR_T needs a trigger.match.');
                        }
                        break;

                    case ContractForm::THR_C->value:
                        if (!isset($trigger['threshold_count']) || !is_numeric($trigger['threshold_count'])) {
                            $validator->errors()->add('trigger.threshold_count', 'THR_C needs a numeric threshold_count.');
                        }
                        if (!isset($trigger['match']) || !is_array($trigger['match'])) {
                            $validator->errors()->add('trigger.match', 'THR_C needs a trigger.match.');
                        }
                        break;

                    case ContractForm::ACC->value:
                        if (trim((string) ($trigger['target'] ?? '')) === '') {
                            $validator->errors()->add('trigger.target', 'ACC needs a target state dimension.');
                        }
                        break;

                    case ContractForm::DEC->value:
                        if (trim((string) ($trigger['target'] ?? '')) === '') {
                            $validator->errors()->add('trigger.target', 'DEC needs a target state dimension.');
                        }
                        break;
                }
            },
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.regex'           => 'Name may contain only letters, numbers, underscore and hyphen.',
            'form.in'              => 'Form must be one of THR_T, THR_C, ACC, DEC.',
            'trigger.required'     => 'A trigger is required.',
            'action.required'      => 'An action is required.',
            'action.type.required' => 'The action needs a type.',
            'action.type.in'       => 'Action type must be one of set_flag, create_goal, nudge_state, inject_memo.',
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

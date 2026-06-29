<?php

namespace App\Http\Requests\Admin\Behavior;

use Illuminate\Validation\Rule;

/**
 * Validates the ENVELOPE of a pattern plus the per-kind trigger fields a trigger
 * actually needs, so a pattern can't be saved in a shape that silently never
 * activates — the same discipline StoreContractRequest applies to contracts.
 *
 * The deeper invariants (quota requires immune, status legality) also live in
 * BehaviorPatternService::validate() and run in save(); this request does not
 * duplicate those. What it adds is the set of trigger fields whose absence makes
 * a pattern a silent no-op:
 *
 *   - mood trigger with no `target`/`op`/`value` → never crosses, never fires
 *   - pulse trigger with no `from`/`to`          → no range, never fires
 *   - trigger with an unknown `kind`             → no evaluator, pattern inert
 *
 * Catching these here turns a pattern that "exists but can never win" into a
 * clear, early error.
 *
 * after() is used for the conditional parts because the required field depends on
 * trigger.kind, which static rules can't express cleanly.
 */
class StoreBehaviorRequest extends BaseBehaviorRequest
{
    /** Trigger kinds the selector has evaluators for. Keep in sync with the registry. */
    private const KNOWN_KINDS = ['mood', 'pulse'];

    /** Comparison operators the mood evaluator accepts. */
    private const MOOD_OPS = ['>', '>=', '<', '<=', '==', '!='];

    public function rules(): array
    {
        return array_merge($this->presetRules(), [
            'name'        => 'required|string|max:120|regex:/^[a-zA-Z0-9_\-]+$/',
            'trigger'     => 'required|array',
            'trigger.kind' => ['required', 'string', Rule::in(self::KNOWN_KINDS)],
            'intent'      => 'nullable|string|max:2000',
            'behavior'    => 'nullable|array',
            'constraints' => 'nullable|array',
            'priority'    => 'nullable|numeric|min:0|max:1000',
            'immune'      => 'nullable|boolean',
            'forced_activation_interval' => 'nullable|integer|min:1|max:100000',
            'status'      => ['nullable', Rule::in(['hypothesis', 'active', 'retired'])],
            'provenance'  => 'nullable|string|max:64',
            'plasticity'  => 'nullable|numeric|min:0|max:10',
        ]);
    }

    /**
     * Conditional checks that depend on trigger.kind and the immune/quota pairing.
     */
    public function after(): array
    {
        return [
            function ($validator) {
                $data    = $this->all();
                $trigger = (array) ($data['trigger'] ?? []);
                $kind    = $trigger['kind'] ?? null;

                // ── Kind-specific required trigger fields ────────────────────
                switch ($kind) {
                    case 'mood':
                        if (trim((string) ($trigger['target'] ?? '')) === '') {
                            $validator->errors()->add('trigger.target', 'mood trigger needs a target dimension (e.g. "focus").');
                        }
                        if (!isset($trigger['op']) || !in_array($trigger['op'], self::MOOD_OPS, true)) {
                            $validator->errors()->add('trigger.op', 'mood trigger needs an op: ' . implode(' ', self::MOOD_OPS) . '.');
                        }
                        if (!isset($trigger['value']) || !is_numeric($trigger['value'])) {
                            $validator->errors()->add('trigger.value', 'mood trigger needs a numeric value.');
                        }
                        break;

                    case 'pulse':
                        if (!isset($trigger['from']) || !is_numeric($trigger['from'])) {
                            $validator->errors()->add('trigger.from', 'pulse trigger needs a numeric "from".');
                        }
                        if (!isset($trigger['to']) || !is_numeric($trigger['to'])) {
                            $validator->errors()->add('trigger.to', 'pulse trigger needs a numeric "to".');
                        }
                        break;
                }

                // ── Quota requires immune ────────────────────────────────────
                // A forced-activation interval is the reservation that protects an
                // immune pattern from starvation. On a non-immune pattern it is
                // meaningless — flag it rather than silently ignore it.
                $immune   = filter_var($data['immune'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $interval = $data['forced_activation_interval'] ?? null;
                if ($interval !== null && !$immune) {
                    $validator->errors()->add(
                        'forced_activation_interval',
                        'forced_activation_interval requires immune=true (the quota protects an immune pattern).'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.regex'        => 'Name may contain only letters, numbers, underscore and hyphen.',
            'trigger.required'  => 'A trigger is required.',
            'trigger.kind.required' => 'The trigger needs a kind.',
            'trigger.kind.in'   => 'Trigger kind must be one of: ' . implode(', ', self::KNOWN_KINDS) . '.',
        ]);
    }

    /**
     * The pattern definition as the canonical array shape, ready for
     * BehaviorPatternService::save().
     */
    public function getDefinitionArray(): array
    {
        $v = $this->validated();

        return array_filter([
            'name'        => $v['name'],
            'trigger'     => $v['trigger'],
            'intent'      => $v['intent'] ?? null,
            'behavior'    => $v['behavior'] ?? null,
            'constraints' => $v['constraints'] ?? null,
            'priority'    => $v['priority'] ?? null,
            'immune'      => $v['immune'] ?? false,
            'forced_activation_interval' => $v['forced_activation_interval'] ?? null,
            'status'      => $v['status'] ?? null,        // null → service defaults to hypothesis
            'provenance'  => $v['provenance'] ?? null,
            'plasticity'  => $v['plasticity'] ?? null,
        ], fn ($x) => $x !== null);
    }
}

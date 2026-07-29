<?php

namespace App\Http\Requests\Admin\Behavior;

use Illuminate\Validation\Rule;

/**
 * Validates the ENVELOPE of a pattern plus the per-kind trigger fields a trigger
 * actually needs, so a pattern can't be saved in a shape that silently never
 * activates — the same discipline StoreContractRequest applies to contracts.
 *
 * Phase 2a adds the LEVER envelope. A lever is optional, but a MALFORMED lever is
 * the silent-no-op trap again: it would enact nothing and produce no discriminating
 * outcome, while looking like a lever in the JSON. So we validate it here at the
 * edge (and again in BehaviorPatternService::validate for the agent-authored path).
 *
 * after() carries the conditional parts (trigger fields by kind, immune/quota
 * pairing, and now lever bounds) because they depend on values static rules can't
 * express cleanly.
 */
class StoreBehaviorRequest extends BaseBehaviorRequest
{
    /** Trigger kinds the selector has evaluators for. Keep in sync with the registry. */
    private const KNOWN_KINDS = ['mood', 'pulse'];

    /** Comparison operators the mood evaluator accepts. */
    private const MOOD_OPS = ['>', '>=', '<', '<=', '==', '!='];

    /** Lever delta bounds — must match BehaviorPatternService::LEVER_DELTA_*. */
    private const LEVER_DELTA_MIN = -0.5;
    private const LEVER_DELTA_MAX = 0.5;

    public function rules(): array
    {
        return array_merge($this->presetRules(), [
            'name'         => 'required|string|max:120|regex:/^[a-zA-Z0-9_\-]+$/',
            'trigger'      => 'required|array',
            'trigger.kind' => ['required', 'string', Rule::in(self::KNOWN_KINDS)],
            'intent'       => 'nullable|string|max:2000',
            'behavior'     => 'nullable|array',
            'constraints'  => 'nullable|array',
            // ── Lever (phase 2a) ─────────────────────────────────────────────
            // Structural shape only; the value checks (non-empty dimension, numeric
            // in-bounds non-zero delta) are in after() so the message can explain
            // WHY a big delta is rejected.
            'lever'           => 'nullable|array',
            'lever.dimension' => 'nullable|string|max:64',
            'lever.delta'     => 'nullable|numeric',
            'priority'        => 'nullable|numeric|min:0|max:1000',
            'immune'          => 'nullable|boolean',
            'forced_activation_interval' => 'nullable|integer|min:1|max:100000',
            'status'          => ['nullable', Rule::in(['hypothesis', 'active', 'retired'])],
            'provenance'      => 'nullable|string|max:64',
            'plasticity'      => 'nullable|numeric|min:0|max:10',
        ]);
    }

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
                $immune   = filter_var($data['immune'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $interval = $data['forced_activation_interval'] ?? null;
                if ($interval !== null && !$immune) {
                    $validator->errors()->add(
                        'forced_activation_interval',
                        'forced_activation_interval requires immune=true (the quota protects an immune pattern).'
                    );
                }

                // ── Lever shape + bounds (phase 2a) ──────────────────────────
                // Only validate if a lever was actually supplied. An absent lever is
                // a valid phase-1 pattern. If supplied, BOTH parts are required and
                // the delta must be a non-zero nudge within bounds.
                $lever = $data['lever'] ?? null;
                if ($lever !== null && $lever !== []) {
                    $dimension = trim((string) ($lever['dimension'] ?? ''));
                    if ($dimension === '') {
                        $validator->errors()->add('lever.dimension', 'A lever needs a dimension to nudge (e.g. "tenderness").');
                    }

                    $delta = $lever['delta'] ?? null;
                    if (!is_numeric($delta)) {
                        $validator->errors()->add('lever.delta', 'A lever needs a numeric delta.');
                    } else {
                        $delta = (float) $delta;
                        if ($delta === 0.0) {
                            $validator->errors()->add('lever.delta', 'A lever delta must be non-zero — a zero nudge moves nothing.');
                        } elseif ($delta < self::LEVER_DELTA_MIN || $delta > self::LEVER_DELTA_MAX) {
                            $validator->errors()->add(
                                'lever.delta',
                                sprintf(
                                    'Keep the lever small: delta must be within [%g, %g]. A lever nudges, it does not '
                                    . 'teleport — a large push fills the dimension and leaves the moment no room to '
                                    . 'show surplus, so the pattern can never confirm.',
                                    self::LEVER_DELTA_MIN,
                                    self::LEVER_DELTA_MAX,
                                )
                            );
                        }
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.regex'            => 'Name may contain only letters, numbers, underscore and hyphen.',
            'trigger.required'      => 'A trigger is required.',
            'trigger.kind.required' => 'The trigger needs a kind.',
            'trigger.kind.in'       => 'Trigger kind must be one of: ' . implode(', ', self::KNOWN_KINDS) . '.',
        ]);
    }

    /**
     * The pattern definition as the canonical array shape, ready for
     * BehaviorPatternService::save().
     *
     * CRITICAL: lever must be assembled here, not just validated in rules(). A
     * field that passes validation but is never put into this array reaches the
     * service as null — the exact silent no-op (cf. input_mode/auto_proceed in
     * phase 1). trigger does both steps; lever must too.
     */
    public function getDefinitionArray(): array
    {
        $v = $this->validated();

        // Assemble the lever only when both parts are present and valid. Partial
        // lever (dimension without delta or vice-versa) collapses to null rather
        // than a half-lever the service would have to reject.
        $lever = null;
        $rawLever = $v['lever'] ?? null;
        if (is_array($rawLever)) {
            $dimension = trim((string) ($rawLever['dimension'] ?? ''));
            $delta     = $rawLever['delta'] ?? null;
            if ($dimension !== '' && is_numeric($delta)) {
                $lever = ['dimension' => $dimension, 'delta' => (float) $delta];
            }
        }

        return array_filter([
            'name'        => $v['name'],
            'trigger'     => $v['trigger'],
            'intent'      => $v['intent'] ?? null,
            'behavior'    => $v['behavior'] ?? null,
            'constraints' => $v['constraints'] ?? null,
            'lever'       => $lever,
            'priority'    => $v['priority'] ?? null,
            'immune'      => $v['immune'] ?? false,
            'forced_activation_interval' => $v['forced_activation_interval'] ?? null,
            'status'      => $v['status'] ?? null,
            'provenance'  => $v['provenance'] ?? null,
            'plasticity'  => $v['plasticity'] ?? null,
        ], fn ($x) => $x !== null);
    }
}

<?php

namespace App\Services\Agent\Contract;

/**
 * ContractDefinition — the immutable, execution-side representation of a contract.
 *
 * This is what the engine works with. It carries no Eloquent, touches no DB,
 * and is never mutated: a status change produces a *new* definition via
 * withStatus(), which ContractService then persists. Immutability is the whole
 * point of separating this from the Contract model — the tick loop can hold,
 * compare, and pass these around with zero side effects.
 *
 * The Contract model converts to this via toDomain(); ContractService converts
 * back when saving. Those two methods are the only bridge between storage and
 * execution.
 *
 * Trigger fields stay a loose array because their shape varies by form
 * (THR_T/THR_C carry match + threshold; ACC/DEC carry target + coefficients).
 * The engine reads them per-form; this DTO only guarantees the envelope and the
 * cross-field invariants in validate().
 */
final class ContractDefinition
{
    /**
     * @param array<int, array{from:string,to:string,at:string,reason:?string}> $history
     */
    public function __construct(
        public readonly string          $name,
        public readonly ContractForm    $form,
        public readonly ContractStatus  $status,
        public readonly bool            $vital,
        public readonly array           $trigger,        // form-specific
        public readonly array           $action,         // {type, flag|text|target|delta}
        public readonly ?ContractMatch  $match,          // present for THR_T/THR_C
        public readonly string          $source,         // top-level trace origin
        public readonly ?string         $suspendWhen,    // flag name → auto-suspend while set
        public readonly float           $confidence,
        public readonly array           $history = [],
    ) {
    }

    public static function fromArray(array $a): self
    {
        $form = ContractForm::tryFrom((string) ($a['form'] ?? ''))
            ?? throw new \InvalidArgumentException('Unknown contract form: ' . ($a['form'] ?? '(none)'));

        $status = ContractStatus::tryFrom((string) ($a['status'] ?? 'hypothesis'))
            ?? ContractStatus::HYPOTHESIS;

        $trigger = (array) ($a['trigger'] ?? []);

        // Match lives inside trigger for THR_T / THR_C.
        $match = null;
        if ($form->usesMatch() && isset($trigger['match']) && is_array($trigger['match'])) {
            $match = ContractMatch::fromArray($trigger['match']);
        }

        return new self(
            name:        (string) ($a['name'] ?? ''),
            form:        $form,
            status:      $status,
            vital:       (bool) ($a['vital'] ?? false),
            trigger:     $trigger,
            action:      (array) ($a['action'] ?? []),
            match:       $match,
            source:      (string) ($a['source'] ?? ($match?->source ?? 'engine')),
            suspendWhen: isset($a['suspend_when']) && $a['suspend_when'] !== ''
                ? (string) $a['suspend_when']
                : null,
            confidence:  isset($a['confidence']) ? (float) $a['confidence'] : 1.0,
            history:     array_values((array) ($a['history'] ?? [])),
        );
    }

    /**
     * Flat array for persistence / JSON. `match` is kept nested inside trigger
     * (where it already lives), so this round-trips with fromArray().
     */
    public function toArray(): array
    {
        return array_filter([
            'name'         => $this->name,
            'form'         => $this->form->value,
            'status'       => $this->status->value,
            'vital'        => $this->vital,
            'trigger'      => $this->trigger,
            'action'       => $this->action,
            'source'       => $this->source,
            'suspend_when' => $this->suspendWhen,
            'confidence'   => $this->confidence,
            'history'      => $this->history,
        ], fn ($v) => $v !== null);
    }

    /**
     * Return a new definition with a changed status and an appended history
     * entry. The current instance is untouched — mutation lives in the model
     * via ContractService::save(), never here.
     */
    public function withStatus(ContractStatus $to, ?string $reason = null): self
    {
        $entry = [
            'from'   => $this->status->value,
            'to'     => $to->value,
            'at'     => now()->toISOString(),
            'reason' => $reason,
        ];

        return new self(
            name:        $this->name,
            form:        $this->form,
            status:      $to,
            vital:       $this->vital,
            trigger:     $this->trigger,
            action:      $this->action,
            match:       $this->match,
            source:      $this->source,
            suspendWhen: $this->suspendWhen,
            confidence:  $this->confidence,
            history:     [...$this->history, $entry],
        );
    }

    /**
     * Cross-field invariants. Returns a list of human-readable errors;
     * empty list means valid. Kept separate from fromArray so callers can
     * parse-then-report rather than throw mid-construction.
     */
    public function validate(): array
    {
        $errors = [];

        if ($this->name === '') {
            $errors[] = 'Contract name is required.';
        }

        if ($this->form->usesMatch()) {
            if ($this->match === null) {
                $errors[] = "Form {$this->form->value} requires a trigger.match.";
            }
            if ($this->form === ContractForm::THR_T && !isset($this->trigger['threshold_seconds'])) {
                $errors[] = 'THR_T requires trigger.threshold_seconds.';
            }
            if ($this->form === ContractForm::THR_C && !isset($this->trigger['threshold_count'])) {
                $errors[] = 'THR_C requires trigger.threshold_count.';
            }
        }

        if ($this->form->usesStateVector() && !isset($this->trigger['target'])) {
            $errors[] = "Form {$this->form->value} requires trigger.target (a state-vector dimension).";
        }

        if (!isset($this->action['type'])) {
            $errors[] = 'action.type is required.';
        }

        // Vital eligibility — the central safety rule.
        if ($this->vital && $this->match !== null && !$this->match->matchMode->vitalEligible()) {
            $errors[] = 'A vital contract cannot use a semantic match_mode '
                . '(probabilistic match cannot back a safety interlock).';
        }
        // NOTE: the source-tier rule (vital must use a first-tier source) is an
        // authoring discipline surfaced in `show`, not enforced here, because
        // `source` is a free string. See spec → Source tiers.

        return $errors;
    }
}

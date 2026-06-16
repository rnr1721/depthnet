<?php

namespace App\Services\Agent\Contract;

/**
 * A `match` describes which existing trace a THR_T / THR_C contract looks at.
 * The engine adds no new event log — it queries traces already written.
 *
 * For now the only source is the journal (agent_journal), whose fixed schema
 * maps directly onto ready Eloquent scopes:
 *
 *   type     → scopeOfType      (action|reflection|decision|error|observation|interaction)
 *   outcome  → scopeWithOutcome (success|failure|pending)
 *   window   → scopeBetween     (supplied by the form's threshold)
 *   contains → text match against `summary`, resolved per MatchMode
 *
 * Any field left null is simply not constrained.
 */
final class ContractMatch
{
    public function __construct(
        public readonly string     $source,        // 'journal' (only source for now)
        public readonly ?string    $type    = null,
        public readonly ?string    $outcome = null,
        public readonly ?string    $contains = null,
        public readonly MatchMode  $matchMode = MatchMode::STRICT,
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self(
            source:    (string) ($a['source'] ?? 'journal'),
            type:      isset($a['type']) ? (string) $a['type'] : null,
            outcome:   isset($a['outcome']) ? (string) $a['outcome'] : null,
            contains:  isset($a['contains']) ? (string) $a['contains'] : null,
            matchMode: isset($a['match_mode'])
                ? (MatchMode::tryFrom((string) $a['match_mode']) ?? MatchMode::STRICT)
                : MatchMode::STRICT,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'source'     => $this->source,
            'type'       => $this->type,
            'outcome'    => $this->outcome,
            'contains'   => $this->contains,
            'match_mode' => $this->matchMode->value,
        ], fn ($v) => $v !== null);
    }
}

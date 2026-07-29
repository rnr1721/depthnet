<?php

namespace App\Services\Agent\Exchange\DTO;

/**
 * Outcome of a completed import.
 *
 * Carries the ids created (so the UI can link to them) and any non-blocking
 * warnings that were surfaced during preflight and carried through.
 */
class ImportResult
{
    /** @var int[] Newly created preset ids. */
    public array $presetIds = [];

    /** @var int[] Newly created agent ids. */
    public array $agentIds = [];

    /** @var string[] Non-blocking warnings carried from preflight. */
    public array $warnings = [];

    public function toArray(): array
    {
        return [
            'success'     => true,
            'preset_ids'  => $this->presetIds,
            'agent_ids'   => $this->agentIds,
            'warnings'    => $this->warnings,
            'preset_count' => count($this->presetIds),
            'agent_count' => count($this->agentIds),
        ];
    }
}

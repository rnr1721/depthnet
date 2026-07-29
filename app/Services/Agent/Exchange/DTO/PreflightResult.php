<?php

namespace App\Services\Agent\Exchange\DTO;

/**
 * Outcome of import preflight — accumulated, never fail-fast.
 *
 * errors block the import (fail-closed). warnings do not — the import proceeds
 * and the warning is surfaced in the report. See the import spec's error/warning
 * split: "missing load-bearing → error; degraded optional → warning".
 */
class PreflightResult
{
    /** @var string[] Blocking problems. Non-empty ⇒ import must not run. */
    public array $errors = [];

    /** @var string[] Non-blocking problems. Shown in the report; import proceeds. */
    public array $warnings = [];

    /** Summary counts for the report footer. */
    public int $presetCount = 0;
    public int $agentCount  = 0;
    public ?int $formatVersion = null;

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function toArray(): array
    {
        return [
            'ok'             => !$this->hasErrors(),
            'errors'         => $this->errors,
            'warnings'       => $this->warnings,
            'preset_count'   => $this->presetCount,
            'agent_count'    => $this->agentCount,
            'format_version' => $this->formatVersion,
        ];
    }
}

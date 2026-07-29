<?php

namespace App\Contracts\Agent\Contract;

use App\Models\AiPreset;
use App\Services\Agent\Contract\ContractDefinition;
use App\Services\Agent\Contract\ContractStatus;

/**
 * ContractService — the single boundary between contract storage and execution.
 *
 * Storage (the Contract Eloquent model) and execution (the ContractDefinition
 * DTO) never meet except here. Reads return DTOs; writes accept DTOs, validate
 * them, and persist to the model. The engine depends only on this interface and
 * only ever sees DTOs — never Eloquent.
 *
 * clear() places contracts in the same row as journal/heart/ontology in
 * PresetCleanupService, so wiping a preset stays uniform across subsystems.
 */
interface ContractServiceInterface
{
    /**
     * All contracts for a preset, as immutable DTOs.
     *
     * @return ContractDefinition[]
     */
    public function all(AiPreset $preset): array;

    /**
     * Only contracts in the given status (e.g. ACTIVE for the tick loop).
     *
     * @return ContractDefinition[]
     */
    public function ofStatus(AiPreset $preset, ContractStatus $status): array;

    /**
     * Fetch one contract by name, or null if absent.
     */
    public function find(AiPreset $preset, string $name): ?ContractDefinition;

    /**
     * Validate and persist a definition (insert or update by name).
     *
     * Returns ['success' => bool, 'message' => string, 'errors' => string[]].
     * On validation failure nothing is written and errors are returned.
     */
    public function save(AiPreset $preset, ContractDefinition $definition): array;

    /**
     * Transition a contract to a new status, appending a history entry.
     * Enforces the legal lifecycle transitions and the vital interlock.
     *
     * When transitioning INTO active, $maxActive caps how many contracts may be
     * active at once for the preset (0 = no cap). The cap is a soft guard against
     * context bloat / tick cost, surfaced as a clear refusal — not a data
     * invariant — so it lives here at the transition boundary, not in the engine.
     *
     * Returns ['success' => bool, 'message' => string].
     */
    public function transition(
        AiPreset $preset,
        string $name,
        ContractStatus $to,
        ?string $reason = null,
        int $maxActive = 0
    ): array;

    /**
     * Delete a single contract by name. Returns true if a row was removed.
     */
    public function delete(AiPreset $preset, string $name): bool;


    /**
     * Remove all contracts for a preset. Used by PresetCleanupService.
     * Returns the number of contracts removed.
     */
    public function clear(AiPreset $preset): int;

}

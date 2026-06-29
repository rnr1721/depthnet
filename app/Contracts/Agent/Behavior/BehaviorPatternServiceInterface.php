<?php

namespace App\Contracts\Agent\Behavior;

use App\Models\AiPreset;
use App\Models\BehaviorPattern;

/**
 * BehaviorPatternServiceInterface — the single bridge between the BehaviorPattern
 * model (storage) and the agent/plugin (authoring).
 *
 * Mirrors ContractServiceInterface: reads return models/arrays, writes validate
 * and persist, lifecycle enforces the legal status graph, and the immune
 * interlock guards definition edits/deletes of a protected pattern (revoke to
 * hypothesis first). The plugin depends on this and never touches Eloquent
 * directly — same discipline as ContractPlugin over ContractService.
 *
 * NOTE: per-cycle hot writes (fitness, activations, decay) do NOT go through here
 * — they go through BehaviorRuntimeServiceInterface (query builder). This service
 * is the COLD path: authoring, listing, lifecycle. The split mirrors
 * ContractService (CRUD) vs ContractRuntimeService (hot).
 */
interface BehaviorPatternServiceInterface
{
    /** @return BehaviorPattern[] */
    public function all(AiPreset $preset): array;

    /** @return BehaviorPattern[] */
    public function ofStatus(AiPreset $preset, string $status): array;

    public function find(AiPreset $preset, string $name): ?BehaviorPattern;

    /**
     * Create or update a pattern from a definition array.
     *
     * @return array{success: bool, message: string, errors: string[]}
     */
    public function save(AiPreset $preset, array $definition): array;

    /**
     * Move a pattern between lifecycle states (active | hypothesis | retired),
     * enforcing the legal transition graph.
     *
     * @return array{success: bool, message: string}
     */
    public function transition(AiPreset $preset, string $name, string $to, ?string $reason = null): array;

    public function delete(AiPreset $preset, string $name): bool;

    public function clear(AiPreset $preset): int;
}

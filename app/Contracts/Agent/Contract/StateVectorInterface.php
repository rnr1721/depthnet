<?php

namespace App\Contracts\Agent\Contract;

use App\Models\AiPreset;

/**
 * StateVectorInterface — a map of named scalar dimensions the engine can read
 * and move.
 *
 * Forms ACC, DEC, and the nudge_state action operate on this vector. The engine
 * depends only on this interface, never on a concrete plugin. One provider backs
 * it (currently MoodPlugin, whose emotional states are a subset of the vector);
 * if no provider is present, NullStateVector is bound and every dimension is
 * absent — contracts that reference the vector are marked unsatisfiable and
 * skipped, with no error.
 *
 * All methods are preset-scoped: the engine may tick in the background, outside
 * any plugin execution context, so state must be addressable by preset alone.
 */
interface StateVectorInterface
{
    /**
     * Whether a real provider backs the vector for this preset. When false,
     * the engine skips ACC/DEC/nudge contracts as unsatisfiable.
     */
    public function isAvailable(AiPreset $preset): bool;

    /**
     * Current value of a dimension, or $default if it does not exist yet.
     */
    public function get(AiPreset $preset, string $key, float $default = 0.0): float;

    /**
     * Set a dimension's value, creating it if absent.
     */
    public function set(AiPreset $preset, string $key, float $value): void;

    /**
     * Whether a dimension currently exists.
     */
    public function has(AiPreset $preset, string $key): bool;

    /**
     * The whole vector as name => value.
     *
     * @return array<string, float>
     */
    public function all(AiPreset $preset): array;
}

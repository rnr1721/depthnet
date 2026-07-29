<?php

namespace App\Services\Agent\Contract;

use App\Contracts\Agent\Contract\StateVectorInterface;
use App\Models\AiPreset;

/**
 * NullStateVector — the no-provider fallback.
 *
 * Bound to StateVectorInterface when no state-vector provider (e.g. MoodPlugin)
 * is available. Everything is absent: the engine sees isAvailable() === false
 * and skips ACC/DEC/nudge contracts as unsatisfiable. No errors, no coupling.
 *
 * This is the same optional-dependency discipline already used for
 * MoodInfluencerInterface: the contract engine never hard-depends on mood.
 */
final class NullStateVector implements StateVectorInterface
{
    public function isAvailable(AiPreset $preset): bool
    {
        return false;
    }

    public function get(AiPreset $preset, string $key, float $default = 0.0): float
    {
        return $default;
    }

    public function set(AiPreset $preset, string $key, float $value): void
    {
        // no-op
    }

    public function has(AiPreset $preset, string $key): bool
    {
        return false;
    }

    public function all(AiPreset $preset): array
    {
        return [];
    }
}

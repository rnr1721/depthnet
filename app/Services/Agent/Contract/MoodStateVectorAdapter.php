<?php

namespace App\Services\Agent\Contract;

use App\Contracts\Agent\Contract\StateVectorInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Models\AiPreset;

/**
 * MoodStateVectorAdapter — exposes MoodPlugin's emotional states as the engine's
 * state vector.
 *
 * Mood stores states under metadata key mood/states as
 *   { "<emotion>": {intensity, decay_rate, cycles, source}, ... }
 * This adapter maps a dimension's scalar value to/from `intensity`, preserving
 * the rest of the envelope so MoodPlugin keeps working unchanged.
 *
 * COUPLING NOTE: this adapter knows mood's storage key and envelope shape. It is
 * the one place that knowledge leaks outside MoodPlugin. If mood's format ever
 * changes, update here. The alternative — having MoodPlugin implement
 * StateVectorInterface directly (as it does MoodInfluencerInterface) — is cleaner
 * ownership and can be adopted later; this adapter keeps the change non-invasive
 * for now.
 *
 * MOOD COEXISTENCE: while both run, mood's own decay (beat/autobeat) and the
 * engine's DEC form may both move the same dimension. The spec treats mood decay
 * as a reference DEC that eventually migrates into the engine; until then they
 * coexist. Keep contract-driven dimensions distinct from emotions the agent
 * feels directly if you want to avoid double-decay.
 */
final class MoodStateVectorAdapter implements StateVectorInterface
{
    private const PLUGIN = 'mood';
    private const KEY    = 'states';

    public function __construct(
        protected PluginMetadataServiceInterface $pluginMetadata,
    ) {
    }

    public function isAvailable(AiPreset $preset): bool
    {
        // The provider exists (mood is bound). Per-preset "mood enabled" nuance
        // is not checked here: a disabled-mood preset simply has no stored states,
        // so the vector reads empty. Refine later if needed.
        return true;
    }

    public function get(AiPreset $preset, string $key, float $default = 0.0): float
    {
        $states = $this->load($preset);
        return isset($states[$key]['intensity'])
            ? (float) $states[$key]['intensity']
            : $default;
    }

    public function set(AiPreset $preset, string $key, float $value): void
    {
        $states = $this->load($preset);

        if (isset($states[$key])) {
            $states[$key]['intensity'] = round($value, 3);
        } else {
            $states[$key] = [
                'intensity'  => round($value, 3),
                'decay_rate' => 0.08,        // mood default; mood retunes known emotions itself
                'cycles'     => 0,
                'source'     => 'contract',  // marks engine-originated dimensions
            ];
        }

        $this->save($preset, $states);
    }

    public function has(AiPreset $preset, string $key): bool
    {
        return isset($this->load($preset)[$key]);
    }

    public function all(AiPreset $preset): array
    {
        $out = [];
        foreach ($this->load($preset) as $key => $state) {
            $out[$key] = (float) ($state['intensity'] ?? 0.0);
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Private — mirrors MoodPlugin's load/save of the same metadata key.
    // -------------------------------------------------------------------------

    private function load(AiPreset $preset): array
    {
        $raw = $this->pluginMetadata->get($preset, self::PLUGIN, self::KEY, '{}');
        return is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
    }

    private function save(AiPreset $preset, array $states): void
    {
        $this->pluginMetadata->set($preset, self::PLUGIN, self::KEY, json_encode($states));
    }
}

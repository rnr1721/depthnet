<?php

namespace App\Services\Agent\Behavior\Enactors;

use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Models\AiPreset;

/**
 * MoodVectorService — the one honest write channel ABS needs into mood.
 *
 * The problem this solves (named precisely so the seam is clear): mood already has
 * two write paths, and NEITHER fits a lever applied from inside the engine.
 *
 *   - MoodPlugin::pushSignal() (the Heart path) is correctly ADDITIVE and soft,
 *     but it depends on a cachedContext set during registerShortcodes(). Outside a
 *     plugin execution context — e.g. a background tick — cachedContext is null and
 *     the signal is silently swallowed. A lever must work in any tick.
 *
 *   - MoodStateVectorAdapter::set() (the contract path) is context-FREE (works from
 *     AiPreset alone), but it OVERWRITES intensity with an absolute value and
 *     stamps source='contract'. A lever applied through set() would clobber an
 *     emotion the agent feels "itself" and mislabel its origin. That is seizure,
 *     not a nudge.
 *
 * This service is the missing third: ADDITIVE like pushSignal, CONTEXT-FREE like
 * set. It reaches the same metadata key both already use (mood/states), adds a
 * signed delta (capped to [0,1]), preserves the rest of each state's envelope, and
 * stamps a SPECIFIC source — "behavior:<pattern_name>" — so the admin can read
 * "why is tenderness creeping?" straight off [mood state]. Provenance of the shift
 * is visible, which is exactly the traceability Eugeny chose over bare 'behavior'.
 *
 * It does NOT own decay, prune, parse, or autobeat — those stay in MoodPlugin,
 * they are about the agent's own commands. We extract exactly ONE operation: an
 * additive nudge addressable by preset. Surgical. MoodPlugin and the adapter keep
 * working unchanged.
 *
 * RETURNS THE REAL APPLIED DELTA. If the dimension was at 0.95 and the lever asked
 * +0.15, only +0.05 lands (ceiling 1.0) and 0.05 is returned. The discriminator
 * subtracts what was REALLY pushed, never the requested amount — otherwise a lever
 * clipped by the ceiling would look like it injected more than it did, and the
 * surplus credit would be wrong.
 *
 * COUPLING NOTE: like MoodStateVectorAdapter, this knows mood's storage key and
 * envelope shape — the same single, documented leak. If mood's format changes,
 * update both here and the adapter.
 */
class MoodVectorService
{
    private const PLUGIN = 'mood';
    private const KEY    = 'states';

    /** Mood default decay for a freshly-created dimension (mirrors the adapter). */
    private const DEFAULT_DECAY = 0.08;

    public function __construct(
        protected PluginMetadataServiceInterface $pluginMetadata,
    ) {
    }

    /**
     * Additively nudge one mood dimension by a signed delta, clamped so intensity
     * stays in [0,1]. Creates the dimension if absent. Returns the REAL signed
     * delta applied (after clamping) — this is the lever push the discriminator
     * subtracts.
     *
     * @param string $source e.g. "behavior:tenderness_on_vulnerability"
     */
    public function nudge(AiPreset $preset, string $dimension, float $delta, string $source): float
    {
        $states = $this->load($preset);

        $before = isset($states[$dimension]['intensity'])
            ? (float) $states[$dimension]['intensity']
            : 0.0;

        $after = max(0.0, min(1.0, $before + $delta));
        $applied = round($after - $before, 6);

        // Nothing actually moved (e.g. delta pushed past a ceiling/floor already
        // reached) — don't write, don't fabricate a state. Return 0.0 honestly.
        if ($applied === 0.0) {
            return 0.0;
        }

        if (isset($states[$dimension])) {
            $states[$dimension]['intensity'] = round($after, 3);
            // Mark the most recent mover without erasing the agent's own feeling:
            // the envelope (decay_rate, cycles) is preserved; only source is
            // updated so the provenance of the latest shift is legible.
            $states[$dimension]['source'] = $source;
        } else {
            $states[$dimension] = [
                'intensity'  => round($after, 3),
                'decay_rate' => self::DEFAULT_DECAY,
                'cycles'     => 0,
                'source'     => $source,
            ];
        }

        $this->save($preset, $states);

        return $applied;
    }

    /** Current intensity of a dimension, or 0.0 if absent. Read-only. */
    public function read(AiPreset $preset, string $dimension): float
    {
        $states = $this->load($preset);
        return isset($states[$dimension]['intensity'])
            ? (float) $states[$dimension]['intensity']
            : 0.0;
    }

    // ── Private — mirrors MoodPlugin's load/save of the same metadata key ──────

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

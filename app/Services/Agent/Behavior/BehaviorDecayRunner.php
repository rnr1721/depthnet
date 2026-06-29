<?php

namespace App\Services\Agent\Behavior;

use App\Contracts\Agent\Behavior\BehaviorDecayRunnerInterface;
use App\Contracts\Agent\Behavior\BehaviorRuntimeServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Models\AiPreset;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * BehaviorDecayRunner — the SECOND clock-hand of ABS.
 *
 * The selector/credit path runs synchronously inside the thinking cycle, where
 * event outcomes are known. But inactivity decay is a STATEFUL, TIME-based
 * process: a pattern that stops winning must lose fitness over wall-time, not per
 * event — otherwise a sleeping pattern keeps its old fitness forever and never
 * yields. That can't be done in the synchronous path (a dormant pattern, by
 * definition, isn't running there to decay itself). So it lives on its own tick,
 * exactly like the contract metabolism.
 *
 * This is a deliberate clone of ContractTickRunner's discipline:
 *   - yield to the thinking cycle: if task_lock_{id} is held, the agent may be
 *     moving fitness/seq right now — SKIP this preset this tick (don't wait,
 *     don't steal). Thinking outranks decay; we catch up next run.
 *   - our own behavior_decay_{id} lock guards against two decay runs of the same
 *     preset overlapping.
 *   - one bad preset never sinks the rest.
 *
 * Decay axis is cycle_seq, same as everything in ABS — but here we compare the
 * pattern's last_activation_seq against the preset's CURRENT cycle_seq. A pattern
 * idle for more than `idle_grace` cycles is decayed. Time enters only through how
 * often this runner fires (cron cadence); the magnitude is purely seq-based, so
 * a paused agent doesn't bleed fitness while genuinely stopped (its cycle_seq
 * isn't advancing either — no new idleness accrues). That symmetry is why the
 * seq axis is right here too: decay tracks cognitive inactivity, not wall-time.
 */
class BehaviorDecayRunner implements BehaviorDecayRunnerInterface
{
    /** Mirrors AgentJobService::LOCK_PREFIX — the thinking-cycle lock. */
    private const THINK_LOCK_PREFIX = 'task_lock_';

    /** Our own per-preset decay lock. */
    private const DECAY_LOCK_PREFIX = 'behavior_decay_';

    /** Decay lock TTL (seconds) — safety net if the process dies mid-run. */
    private const DECAY_LOCK_TTL = 120;

    private const META_PLUGIN  = 'behavior';
    private const META_ENABLED = 'enabled';

    public function __construct(
        protected PresetRegistryInterface         $presetRegistry,
        protected BehaviorRuntimeServiceInterface $runtime,
        protected PluginMetadataServiceInterface  $meta,
        protected Cache                           $cache,
        protected LoggerInterface                 $logger,
    ) {
    }

    /**
     * Decay every ABS-enabled preset. Returns one row per CONSIDERED preset for
     * the command's table/logs.
     *
     * @param float $factor     multiplicative decay applied to idle patterns
     * @param int   $idleGrace  cycles of inactivity before decay kicks in
     * @return array<int, array{preset_id:int, preset:string, outcome:string, detail:string}>
     */
    public function decayAll(float $factor = 0.98, int $idleGrace = 3): array
    {
        $rows = [];

        foreach ($this->presetRegistry->getActivePresets() as $preset) {
            $row = $this->decayOnePreset($preset, $factor, $idleGrace);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Decay a single preset by id — used by `behavior:decay --preset=N` for
     * hands-on debugging. Still respects enablement and locks.
     *
     * @return array{preset_id:int, preset:string, outcome:string, detail:string}|null
     */
    public function decayPreset(int $presetId, float $factor = 0.98, int $idleGrace = 3): ?array
    {
        $preset = $this->presetRegistry->getPresetOrDefault($presetId);

        if ($preset->getId() !== $presetId) {
            return null;
        }

        return $this->decayOnePreset($preset, $factor, $idleGrace);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{preset_id:int, preset:string, outcome:string, detail:string}|null
     *         Null when ABS is disabled for this preset (not a candidate).
     */
    private function decayOnePreset(AiPreset $preset, float $factor, int $idleGrace): ?array
    {
        $presetId = $preset->getId();

        // ABS off for this preset → not a candidate, no row.
        if (!(bool) $this->meta->get($preset, self::META_PLUGIN, self::META_ENABLED, false)) {
            return null;
        }

        // Yield to the thinking cycle: its fitness writes win this run.
        if ($this->cache->has(self::THINK_LOCK_PREFIX . $presetId)) {
            return $this->row($preset, 'skipped', 'agent thinking');
        }

        // Guard against overlapping decay runs of this same preset.
        $lock = $this->cache->lock(self::DECAY_LOCK_PREFIX . $presetId, self::DECAY_LOCK_TTL);

        if (!$lock->get()) {
            return $this->row($preset, 'skipped', 'decay already running');
        }

        try {
            $currentSeq = $this->runtime->currentCycleSeq($preset);

            // Nothing has run yet — no idleness to accrue.
            if ($currentSeq === 0) {
                return $this->row($preset, 'idle', 'no cycles yet');
            }

            // Patterns idle longer than the grace window lose fitness. The floor
            // is current - grace: a pattern whose last_activation_seq is below it
            // (or null) is decayed.
            $seqFloor = $currentSeq - max(0, $idleGrace);

            $affected = $this->runtime->decayInactive($preset, $seqFloor, $factor);

            return $this->row(
                $preset,
                'decayed',
                "seq={$currentSeq} floor={$seqFloor} factor={$factor} affected={$affected}",
            );
        } catch (\Throwable $e) {
            $this->logger->error('BehaviorDecayRunner: decay failed', [
                'preset_id' => $presetId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return $this->row($preset, 'error', $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{preset_id:int, preset:string, outcome:string, detail:string}
     */
    private function row(AiPreset $preset, string $outcome, string $detail): array
    {
        return [
            'preset_id' => $preset->getId(),
            'preset'    => $preset->getName(),
            'outcome'   => $outcome,
            'detail'    => $detail,
        ];
    }
}

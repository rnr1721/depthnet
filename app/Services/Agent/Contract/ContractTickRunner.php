<?php

namespace App\Services\Agent\Contract;

use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginManagerFactoryInterface;
use App\Models\AiPreset;
use App\Services\Agent\Plugins\ContractPlugin;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * ContractTickRunner — drives ContractEngine across presets.
 *
 * This is the metabolism's clock-hand: the engine knows how to tick ONE preset,
 * the runner decides WHICH presets to tick and WHEN to stand aside. All the
 * orchestration that doesn't belong in the engine lives here — preset discovery,
 * the "is the contract plugin even enabled?" check, config extraction, and lock
 * discipline — so the engine stays a pure evaluator and the command stays a thin
 * shell.
 *
 * Lock discipline (the one subtle part):
 *
 *   task_lock_{id}      — held by AgentJobService while the agent is THINKING.
 *                         If it's up, the agent may be moving the same mood
 *                         vector the engine reads/writes, so we SKIP this preset
 *                         this tick (we don't wait, don't steal it). Thinking
 *                         outranks metabolism; the engine catches up next minute.
 *                         Contracts tolerate a missed tick by design (dt + coalesce).
 *
 *   contract_tick_{id}  — our own short lock, guarding against two ticks of the
 *                         SAME preset overlapping (a slow semantic journal match
 *                         while cron fires the next minute). Atomic get/release.
 *
 * The check-then-lock pair isn't atomic against task_lock, leaving a millisecond
 * window where both could touch the mood blob. For an emotional vector that
 * already drifts continuously this is noise, not a correctness problem — see the
 * adapter's coupling note. We don't build a distributed transaction for it.
 */
class ContractTickRunner
{
    /** Mirrors AgentJobService::LOCK_PREFIX — the thinking-cycle lock. */
    private const THINK_LOCK_PREFIX = 'task_lock_';

    /** Our own per-preset tick lock. */
    private const TICK_LOCK_PREFIX = 'contract_tick_';

    /** Tick lock TTL (seconds) — safety net if the process dies mid-tick. */
    private const TICK_LOCK_TTL = 120;

    public function __construct(
        protected PresetRegistryInterface       $presetRegistry,
        protected PluginManagerFactoryInterface $pluginManagerFactory,
        protected ContractEngine                $engine,
        protected Cache                         $cache,
        protected LoggerInterface               $logger,
    ) {
    }

    /**
     * Tick every eligible preset. Returns one row per preset that was CONSIDERED
     * (enabled), describing what happened — for the command's table and logs.
     *
     * @return array<int, array{preset_id:int, preset:string, outcome:string, detail:string}>
     */
    public function tickAll(): array
    {
        $rows = [];

        foreach ($this->presetRegistry->getActivePresets() as $preset) {
            $row = $this->tickOnePreset($preset);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Tick a single preset by id, regardless of how preset discovery would have
     * filtered it — used by `contract:tick --preset=N` for hands-on debugging.
     * Still respects enablement and locks (debugging shouldn't fight the agent).
     *
     * @return array{preset_id:int, preset:string, outcome:string, detail:string}|null
     *         Null when the preset isn't found or the contract plugin is disabled.
     */
    public function tickPreset(int $presetId): ?array
    {
        $preset = $this->presetRegistry->getPresetOrDefault($presetId);

        // getPresetOrDefault falls back to the default preset when the id is bad;
        // guard against silently ticking the wrong one.
        if ($preset->getId() !== $presetId) {
            return null;
        }

        return $this->tickOnePreset($preset);
    }

    // -------------------------------------------------------------------------
    // Per-preset
    // -------------------------------------------------------------------------

    /**
     * @return array{preset_id:int, preset:string, outcome:string, detail:string}|null
     *         Null when the contract plugin is disabled for this preset (not a
     *         row worth reporting — it was never a candidate).
     */
    private function tickOnePreset(AiPreset $preset): ?array
    {
        $presetId = $preset->getId();

        $info = $this->pluginManagerFactory->get()
            ->getPluginInfoForPreset(ContractPlugin::PLUGIN_NAME, $preset);

        // Plugin not registered at all, or disabled for this preset → not a
        // candidate. Return null so it doesn't clutter the report.
        if ($info === null || empty($info['enabled'])) {
            return null;
        }

        $config = $info['current_config'] ?? [];

        // Yield to the thinking cycle: if the agent is mid-thought, its own
        // mood writes win this minute. Skip cleanly.
        if ($this->cache->has(self::THINK_LOCK_PREFIX . $presetId)) {
            return $this->row($preset, 'skipped', 'agent thinking');
        }

        // Guard against overlapping ticks of this same preset.
        $lock = $this->cache->lock(self::TICK_LOCK_PREFIX . $presetId, self::TICK_LOCK_TTL);

        if (!$lock->get()) {
            return $this->row($preset, 'skipped', 'tick already running');
        }

        try {
            $suspendFlag = $this->stringConfig($config, 'default_suspend_flag', '');
            $minSeconds  = $this->intConfig($config, 'tick_min_seconds', 60);

            $result = $this->engine->tick(
                $preset,
                $suspendFlag !== '' ? $suspendFlag : null,
                $minSeconds,
            );

            return $this->row($preset, $result->skipped ? 'coalesced' : 'ticked', $result->summary());
        } catch (\Throwable $e) {
            // One bad preset must never sink the rest of the run.
            $this->logger->error('ContractTickRunner: tick failed', [
                'preset_id' => $presetId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return $this->row($preset, 'error', $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function stringConfig(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? $default;
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    private function intConfig(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }
}

<?php

namespace App\Services\Agent\Skills;

use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Models\AiPreset;
use App\Models\Skill;
use Psr\Log\LoggerInterface;

/**
 * SkillLoadService — the loading/presentation plane of the lazy-skills feature.
 *
 * ── THE LOAD-BEARING INVARIANT: HIDING ≠ BLOCKING ────────────────────────────
 * A hidden tool is still fully callable. This service only decides which tools
 * are PRESENTED (in ToolSchemaBuilder / CommandInstructionBuilder) and which skill
 * bodies are INJECTED into context. It NEVER gates execution — CommandExecutor is
 * untouched. If hiding is ever made to block execution "for safety", the model can
 * end up unable to reach a tool it legitimately needs. Do not.
 *
 * ── No flag: the mechanism is DERIVED from data ──────────────────────────────
 * There is NO lazy_tools_enabled column. The mechanism is active for a preset iff
 * SOME skill has tools. Equivalently: the set of HIDDEN tools is non-empty. An empty
 * hidden set IS the off-switch — nothing to filter, zero regression. This fits the
 * planned transparent UI log (skill loaded → tools appeared): a separate "tools
 * declared but gating off" state would be a mute anomaly; deriving from data keeps
 * one cause → one effect.
 *
 * ── What this owns ───────────────────────────────────────────────────────────
 *   - loaded/idle runtime state, in PluginMetadataService namespace 'skills' with
 *     FLAT keys loaded_{N} / idle_{N} (dots would nest under the dot-splitting
 *     PresetMetadataService — flat is deliberate).
 *   - hiddenToolNames(): the ONE-pass set the builders filter by (hot path).
 *   - gatedToolNames()/loadedToolNames(): read-slices for the future UI log (cold path).
 *   - hysteresis bookkeeping (idle counters), for tool-bearing skills only.
 *   - name self-defense: every tool set is intersected with the LIVE plugin registry,
 *     so a renamed/deleted plugin left in a skill's tool list is ignored on read —
 *     never cleaned destructively (that would make the plugin subsystem depend on skills).
 *
 * ── What this does NOT own ───────────────────────────────────────────────────
 *   - the model-facing verbs (list/load/unload) — those live on SkillPlugin's console
 *     and merely SIGNAL; the handler calls into this service.
 *   - skill CRUD / content — that's SkillService (pure data).
 *   - context injection of skill bodies — that's the injection layer; it only asks
 *     this service for loadedSkillNumbers().
 */
class SkillLoadService
{
    /** Metadata namespace for all lazy-skills runtime state on a preset. */
    private const NS = 'skills';

    /** Flat key prefixes (NO dots — see class docblock). */
    private const KEY_LOADED = 'loaded_';
    private const KEY_IDLE   = 'idle_';

    /**
     * Cycles a tool-bearing skill may go unused before auto-unload.
     * Mirrors the PLANNER_STALL_LIMIT idle-counter pattern in AgentActionsHandler.
     * Toolless skills are exempt (no objective usage pulse) — they unload only via
     * an explicit unload().
     */
    public const IDLE_LIMIT = 4;

    public function __construct(
        protected PluginMetadataServiceInterface $meta,
        protected PluginRegistryInterface $registry,
        protected Skill $skillModel,
        protected LoggerInterface $logger,
    ) {
    }

    // ── State transitions ─────────────────────────────────────────────────────

    /**
     * Load skill N: mark it loaded and reset its idle counter.
     * Idempotent. No-op (returns false) if the skill doesn't exist for this preset.
     */
    public function load(AiPreset $preset, int $skillNumber): bool
    {
        if (!$this->skillExists($preset, $skillNumber)) {
            $this->logger->info('SkillLoadService: load skipped — skill not found', [
                'preset_id' => $preset->getId(),
                'skill'     => $skillNumber,
            ]);
            return false;
        }

        $this->meta->set($preset, self::NS, self::KEY_LOADED . $skillNumber, true);
        $this->meta->set($preset, self::NS, self::KEY_IDLE . $skillNumber, 0);

        return true;
    }

    /**
     * Unload skill N: clear its loaded + idle state. Idempotent.
     */
    public function unload(AiPreset $preset, int $skillNumber): void
    {
        if ($this->meta->has($preset, self::NS, self::KEY_LOADED . $skillNumber)) {
            $this->meta->remove($preset, self::NS, self::KEY_LOADED . $skillNumber);
        }
        if ($this->meta->has($preset, self::NS, self::KEY_IDLE . $skillNumber)) {
            $this->meta->remove($preset, self::NS, self::KEY_IDLE . $skillNumber);
        }
    }

    /**
     * Whether skill N is currently loaded.
     */
    public function isLoaded(AiPreset $preset, int $skillNumber): bool
    {
        return (bool) $this->meta->get($preset, self::NS, self::KEY_LOADED . $skillNumber, false);
    }

    /**
     * The names of plugins that currently exist in the registry.
     *
     * Exposed so the [[skills]] inventory (rendered from SkillPlugin) can filter a
     * skill's declared tools down to ones that really exist — a renamed/deleted
     * plugin left in a skill's tool list never shows up as an available capability.
     * Thin pass-through to the registry the service already holds, so the plugin
     * needs only this one dependency (SkillLoadService) rather than its own
     * registry handle.
     *
     * @return string[]
     */
    public function livePluginNames(): array
    {
        return $this->registry->getAvailablePluginNames();
    }

    // ── Hot path: the one-pass hidden set ─────────────────────────────────────

    /**
     * The tool names currently HIDDEN for this preset — the single value the tool
     * builders filter by. Computed in ONE walk over the preset's skills:
     *
     *   for each skill that has tools:
     *     every tool → gated
     *     if the skill is loaded: every tool → loaded (union over loaded owners)
     *   hidden = gated − loaded, then ∩ live registry (name self-defense)
     *
     * Empty result ⟺ mechanism inactive (no skill declares tools, or every gated tool
     * is currently visible) ⟺ builders filter nothing (zero regression). The emptiness
     * IS the off-switch; there is no separate enabled flag.
     *
     * Union semantics fall out naturally: a tool shared by several skills is un-hidden
     * as soon as ANY owning skill is loaded, because that skill adds it to `loaded`.
     *
     * @return string[]  unique, registry-valid tool names to hide
     */
    public function hiddenToolNames(AiPreset $preset): array
    {
        $skills = $this->skillModel->where('preset_id', $preset->getId())->get();

        $gated  = [];
        $loaded = [];

        foreach ($skills as $skill) {
            $tools = $skill->getToolNames();
            if (empty($tools)) {
                continue; // toolless skill contributes nothing
            }

            $isLoaded = $this->isLoaded($preset, (int) $skill->number);

            foreach ($tools as $t) {
                $gated[$t] = true;
                if ($isLoaded) {
                    $loaded[$t] = true;
                }
            }
        }

        $hidden = array_diff_key($gated, $loaded);

        return $this->intersectLiveRegistry(array_keys($hidden));
    }

    // ── Cold path: read-slices for the future UI log ──────────────────────────

    /**
     * All tool names gated by the mechanism — union of getToolNames() over ALL
     * skills, ∩ live registry. FOR THE LOG / diagnostics; the builders do NOT use
     * this (they use hiddenToolNames). Kept separate so the log can show
     * "declared N, loaded M, hidden K".
     *
     * @return string[]
     */
    public function gatedToolNames(AiPreset $preset): array
    {
        $skills = $this->skillModel->where('preset_id', $preset->getId())->get();

        $tools = [];
        foreach ($skills as $skill) {
            foreach ($skill->getToolNames() as $t) {
                $tools[] = $t;
            }
        }

        return $this->intersectLiveRegistry($tools);
    }

    /**
     * Tool names currently VISIBLE via a loaded skill — union over LOADED skills,
     * ∩ live registry. FOR THE LOG; the builders do NOT use this.
     *
     * @return string[]
     */
    public function loadedToolNames(AiPreset $preset): array
    {
        $loadedNumbers = $this->loadedSkillNumbers($preset);
        if (empty($loadedNumbers)) {
            return [];
        }

        $skills = $this->skillModel
            ->where('preset_id', $preset->getId())
            ->whereIn('number', $loadedNumbers)
            ->get();

        $tools = [];
        foreach ($skills as $skill) {
            foreach ($skill->getToolNames() as $t) {
                $tools[] = $t;
            }
        }

        return $this->intersectLiveRegistry($tools);
    }

    // ── Read model for injection + [[skills]] marker ──────────────────────────

    /**
     * The numbers of every currently-loaded skill for this preset.
     *
     * Reconciled against reality: a skill whose row no longer exists (deleted) is
     * dropped AND its stale metadata cleared, so the loaded set never references a
     * ghost. Consumed by the [[skills]] marker and the context-injection layer.
     *
     * @return int[]  sorted ascending
     */
    public function loadedSkillNumbers(AiPreset $preset): array
    {
        $existing = $this->existingSkillNumbers($preset);
        $loaded   = [];

        foreach ($existing as $number) {
            if ($this->isLoaded($preset, $number)) {
                $loaded[] = $number;
            }
        }

        $this->clearOrphanLoadedFlags($preset, $existing);

        sort($loaded);
        return $loaded;
    }

    // ── Hysteresis ────────────────────────────────────────────────────────────

    /**
     * Reset the idle counter for every LOADED skill that owns $usedPluginName.
     *
     * Called by the handler for each plugin that actually executed this cycle.
     * Tool→skill is one-to-many, so a used tool refreshes ALL its loaded owners —
     * resetting only one would let a still-used skill drift to auto-unload.
     */
    public function noteToolUse(AiPreset $preset, string $usedPluginName): void
    {
        foreach ($this->loadedSkillNumbers($preset) as $number) {
            $skill = $this->findSkill($preset, $number);
            if ($skill === null) {
                continue;
            }
            if (in_array($usedPluginName, $skill->getToolNames(), true)) {
                $this->meta->set($preset, self::NS, self::KEY_IDLE . $number, 0);
            }
        }
    }

    /**
     * Advance hysteresis once per completed cycle and auto-unload skills gone cold.
     * ONLY tool-bearing skills participate; toolless skills are never auto-unloaded
     * (no usage pulse) and leave only by explicit unload().
     *
     * Call AFTER noteToolUse() for the cycle's used tools, so a tool used this cycle
     * has already reset its owners to 0 before we increment.
     *
     * @return int[]  numbers auto-unloaded this cycle (for logging/telemetry)
     */
    public function tickHysteresis(AiPreset $preset): array
    {
        $unloaded = [];

        foreach ($this->loadedSkillNumbers($preset) as $number) {
            $skill = $this->findSkill($preset, $number);
            if ($skill === null) {
                continue;
            }

            if (empty($skill->getToolNames())) {
                continue; // toolless — exempt from hysteresis
            }

            $idle = (int) $this->meta->get($preset, self::NS, self::KEY_IDLE . $number, 0);
            $idle++;

            if ($idle >= self::IDLE_LIMIT) {
                $this->unload($preset, $number);
                $unloaded[] = $number;
                $this->logger->info('SkillLoadService: auto-unloaded idle skill', [
                    'preset_id' => $preset->getId(),
                    'skill'     => $number,
                    'idle'      => $idle,
                    'limit'     => self::IDLE_LIMIT,
                ]);
                continue;
            }

            $this->meta->set($preset, self::NS, self::KEY_IDLE . $number, $idle);
        }

        return $unloaded;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Intersect a raw tool-name list with the live plugin registry and dedupe.
     * The single point of "name self-defense" — a renamed/deleted plugin silently
     * drops out on read; nothing is mutated in the DB or metadata.
     *
     * @param  string[] $names
     * @return string[]
     */
    private function intersectLiveRegistry(array $names): array
    {
        $live = $this->registry->getAvailablePluginNames();

        return array_values(array_unique(array_filter(
            $names,
            fn ($n) => in_array($n, $live, true)
        )));
    }

    private function skillExists(AiPreset $preset, int $number): bool
    {
        return $this->skillModel
            ->where('preset_id', $preset->getId())
            ->where('number', $number)
            ->exists();
    }

    private function findSkill(AiPreset $preset, int $number): ?Skill
    {
        return $this->skillModel
            ->where('preset_id', $preset->getId())
            ->where('number', $number)
            ->first();
    }

    /**
     * @return int[]  every skill number that currently exists for this preset
     */
    private function existingSkillNumbers(AiPreset $preset): array
    {
        return $this->skillModel
            ->where('preset_id', $preset->getId())
            ->pluck('number')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Remove loaded_{N}/idle_{N} metadata for any N no longer present among the
     * given existing skill numbers. Keeps the store from accumulating ghost flags.
     *
     * @param int[] $existing
     */
    private function clearOrphanLoadedFlags(AiPreset $preset, array $existing): void
    {
        $all = $this->meta->export($preset, self::NS); // ['loaded_3' => true, 'idle_3' => 0, ...]

        foreach (array_keys($all) as $key) {
            $number = $this->numberFromKey($key);
            if ($number === null) {
                continue;
            }
            if (!in_array($number, $existing, true)) {
                $this->meta->remove($preset, self::NS, $key);
            }
        }
    }

    /**
     * Extract the skill number from a flat metadata key like 'loaded_3' / 'idle_12'.
     * Returns null for any key not matching the two known prefixes.
     */
    private function numberFromKey(string $key): ?int
    {
        foreach ([self::KEY_LOADED, self::KEY_IDLE] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                $rest = substr($key, strlen($prefix));
                return ctype_digit($rest) ? (int) $rest : null;
            }
        }
        return null;
    }
}

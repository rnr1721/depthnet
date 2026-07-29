<?php

namespace App\Services\Agent\Exchange;

use App\Contracts\Agent\Exchange\PresetExporterInterface;
use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Exceptions\Exchange\ExportException;
use App\Models\Agent;
use App\Models\AiPreset;
use App\Models\BehaviorPattern;
use App\Models\AgentSkill;
use App\Models\PresetCapabilityConfig;
use App\Models\PresetPluginData;
use App\Models\Skill;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * PresetExporter
 *
 * Builds a portable "depthnet.bundle" from presets and/or agents.
 *
 * Design notes:
 *  - Read-only. Never writes to the DB. No transaction needed.
 *  - Closure is built once into $collected (preset_id => AiPreset), which also
 *    serves as the cycle-guard and de-duplicator (visited set).
 *  - Every preset gets a bundle-local `ref` UUID assigned in $refMap
 *    (preset_id => ref). All cross-preset links serialize to these refs.
 *  - Secrets are stripped by asking the engine which of its config fields are
 *    declared as `type: password` — NOT by a hardcoded key blacklist.
 *
 * @see PresetExporterInterface
 */
class PresetExporter implements PresetExporterInterface
{
    /** Bundle format identifier and version. Bump version only on breaking format changes. */
    private const FORMAT         = 'depthnet.bundle';
    private const FORMAT_VERSION = 1;

    /**
     * Preset columns that ARE exported (whitelist).
     * The schema test asserts every ai_presets column is either here or in
     * IGNORED_FIELDS — so a newly added column can never silently leak or vanish.
     *
     * NOTE: engine_config is listed here but goes through stripSecrets().
     *       Self-referential links (cycle_prompt_preset_id, target_preset_id)
     *       are NOT here — they are re-expressed as *_ref (see IGNORED_FIELDS).
     */
    public const EXPORTED_FIELDS = [
        'name',
        'description',
        'engine_name',
        'engine_config',
        'input_mode',
        'pool_relative_dates',
        'pulse_dates',
        'agent_result_mode',
        'max_context_limit',
        'max_context_limit_extended',
        'pre_pass_enabled',
        'pre_pass_instruction',
        'loop_interval',
        'before_execution_wait',
        'error_behavior',
        'allow_handoff_to',
        'allow_handoff_from',
        'preset_code',
        'preset_code_next',
        'plugins_disabled',
        'pre_run_commands',
        'turn_trigger',
        'defrag_enabled',
        'defrag_prompt',
        'defrag_keep_per_day',
        'cp_context_limit',
        'voice_mp_commands',
        'default_call_message',
        'metadata',
        'target_plugins_whitelist',
        'rhasspy_enabled',
        'rhasspy_url',
        'rhasspy_tts_voice',
        'rhasspy_incoming_enabled',
    ];

    /**
     * Preset columns deliberately NOT exported, with the reason encoded by grouping.
     * Presence here is as meaningful as EXPORTED_FIELDS — the schema test needs
     * every column classified. Do not remove entries to "clean up".
     */
    public const IGNORED_FIELDS = [
        // identity / instance-local
        'id',
        'created_by',
        'created_at',
        'updated_at',
        // resolved indirectly (active_prompt_id via prompt.is_active)
        'active_prompt_id',
        // instance-local state
        'is_default',
        'is_active',
        // spawn mechanics — ephemeral, never exported
        'is_spawned',
        'parent_preset_id',
        // self-referential links — re-expressed as *_ref instead of raw id
        'cycle_prompt_preset_id',
        'target_preset_id',
        // secret — token lives in a column, stripped explicitly (never exported)
        'rhasspy_incoming_token',
    ];

    /** Capability config keys treated as secret (fallback until drivers declare password fields). */
    private const CAPABILITY_SECRET_KEYS = ['api_key', 'token', 'secret', 'password'];

    /** Collected closure: preset_id => AiPreset. Doubles as visited-set. */
    private array $collected = [];

    /** preset_id => bundle-local ref UUID. */
    private array $refMap = [];

    public function __construct(
        protected AiPreset $presetModel,
        protected Agent $agentModel,
        protected EngineRegistryInterface $engineRegistry,
        protected DatabaseManager $db,
        protected LoggerInterface $logger,
    ) {
    }

    // ── Public facades ──────────────────────────────────────────────────────

    /**
     * @inheritDoc
     */
    public function exportPreset(int $presetId, array $options = []): array
    {
        return $this->exportBundle([$presetId], [], $options);
    }

    /**
     * @inheritDoc
     */
    public function exportAgent(int $agentId, array $options = []): array
    {
        return $this->exportBundle([], [$agentId], $options);
    }

    /**
     * @inheritDoc
     */
    public function exportBundle(array $presetIds, array $agentIds, array $options = []): array
    {
        // Fresh state per call — exporter instance may be reused (it's a bind, not singleton).
        $this->collected = [];
        $this->refMap    = [];

        $includeSkills = (bool) ($options['include_skills'] ?? false);

        $agents = $this->loadAgents($agentIds);

        // Roots: explicit preset ids + every preset an agent points at.
        $rootPresetIds = $presetIds;
        foreach ($agents as $agent) {
            foreach ($this->agentRootPresetIds($agent) as $pid) {
                $rootPresetIds[] = $pid;
            }
        }

        if (empty($rootPresetIds) && empty($agents)) {
            throw new ExportException('Nothing to export: no presets or agents given.');
        }

        // Build the transitive closure from all roots (cycle-safe, de-duplicated).
        foreach (array_unique($rootPresetIds) as $pid) {
            $this->collectClosure((int) $pid);
        }

        // Assign a stable bundle-local ref to every collected preset up front,
        // so link serialization can resolve any target regardless of order.
        foreach ($this->collected as $pid => $_) {
            $this->refMap[$pid] = (string) Str::uuid();
        }

        $presets = [];
        foreach ($this->collected as $preset) {
            $presets[] = $this->serializePreset($preset, $includeSkills);
        }

        $bundle = [
            'format'         => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'exported_at'    => now()->toIso8601String(),
            'source'         => [
                'app_version'   => config('app.version', 'unknown'),
                'instance_hint' => config('app.name', null),
            ],
            'options'        => [
                'include_skills' => $includeSkills,
            ],
            'presets'        => $presets,
            'agents'         => array_map(fn (Agent $a) => $this->serializeAgent($a), $agents),
        ];

        $this->logger->info('PresetExporter: bundle built', [
            'root_presets' => $presetIds,
            'root_agents'  => $agentIds,
            'preset_count' => count($presets),
            'agent_count'  => count($agents),
        ]);

        return $bundle;
    }

    // ── Closure building ────────────────────────────────────────────────────

    /**
     * Recursively collect a preset and everything it references.
     * $collected doubles as the visited-set: an already-seen id returns
     * immediately, which both breaks reference cycles (inner_voice ↔ cycle_prompt)
     * and de-duplicates presets shared across roots.
     */
    private function collectClosure(int $presetId): void
    {
        if (isset($this->collected[$presetId])) {
            return; // cycle broken + de-duplicated
        }

        $preset = $this->presetModel
            ->with(['ragConfigs', 'innerVoiceConfigs'])
            ->find($presetId);

        if (!$preset) {
            throw new ExportException("Preset #{$presetId} not found while building export closure.");
        }

        // Spawned presets are ephemeral and must never enter a bundle.
        if ($preset->isSpawned()) {
            throw new ExportException(
                "Preset #{$presetId} ('{$preset->getName()}') is a spawned/ephemeral preset " .
                "and cannot be exported. Referenced from the export closure."
            );
        }

        $this->collected[$presetId] = $preset;

        foreach ($this->referencedPresetIds($preset) as $refId) {
            if ($refId !== null) {
                $this->collectClosure((int) $refId);
            }
        }
    }

    /**
     * All preset ids this preset points at (excluding parent/spawn links).
     *
     * @return array<int|null>
     */
    private function referencedPresetIds(AiPreset $preset): array
    {
        $ids = [
            $preset->cycle_prompt_preset_id,
            $preset->target_preset_id,
        ];

        foreach ($preset->ragConfigs as $rag) {
            $ids[] = $rag->rag_preset_id;
        }
        foreach ($preset->innerVoiceConfigs as $iv) {
            $ids[] = $iv->voice_preset_id;
        }

        return $ids;
    }

    /**
     * Every preset an agent depends on: planner + each role's preset + validator.
     * These become closure roots. A spawned preset here surfaces as an
     * ExportException inside collectClosure (fail-closed).
     *
     * @return int[]
     */
    private function agentRootPresetIds(Agent $agent): array
    {
        $ids = [$agent->planner_preset_id];

        foreach ($agent->roles as $role) {
            $ids[] = $role->preset_id;
            if ($role->validator_preset_id !== null) {
                $ids[] = $role->validator_preset_id;
            }
        }

        return array_values(array_filter($ids, fn ($id) => $id !== null));
    }

    // ── Serialization: preset ───────────────────────────────────────────────

    private function serializePreset(AiPreset $preset, bool $includeSkills): array
    {
        $out = ['ref' => $this->refMap[$preset->getId()]];

        // Scalar whitelist.
        foreach (self::EXPORTED_FIELDS as $field) {
            if ($field === 'engine_config') {
                $out['engine_config'] = $this->stripSecrets(
                    $preset->engine_config ?? [],
                    $preset->engine_name
                );
                continue;
            }
            $out[$field] = $preset->{$field};
        }

        // Self-referential links → refs (null when target somehow not in closure).
        $out['cycle_prompt_preset_ref'] = $this->refFor($preset->cycle_prompt_preset_id);
        $out['target_preset_ref']       = $this->refFor($preset->target_preset_id);

        // Owned collections.
        $out['prompts']             = $this->serializePrompts($preset);
        $out['known_sources']       = $this->serializeKnownSources($preset);
        $out['plugin_configs']      = $this->serializePluginConfigs($preset);
        $out['capability_configs']  = $this->serializeCapabilityConfigs($preset);
        $out['plugin_data']         = $this->serializePluginData($preset);
        $out['behavior_patterns']   = $this->serializeBehaviorPatterns($preset);
        $out['contracts']           = $this->serializeContracts($preset);
        $out['rag_configs']         = $this->serializeRagConfigs($preset);
        $out['inner_voice_configs'] = $this->serializeInnerVoiceConfigs($preset);

        if ($includeSkills) {
            $out['skills'] = $this->serializeSkills($preset);
        }

        return $out;
    }

    private function serializePrompts(AiPreset $preset): array
    {
        $activeId = $preset->active_prompt_id;

        return $preset->prompts()->orderBy('created_at')->get()->map(fn ($p) => [
            'code'        => $p->code,
            'content'     => $p->getContent(),   // source of truth (revert overwrites it)
            'description' => $p->description,
            'is_active'   => $p->getId() === $activeId,
        ])->all();
    }

    private function serializeKnownSources(AiPreset $preset): array
    {
        return $preset->knownSources()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($k) => [
                'source_name'   => $k->source_name,
                'label'         => $k->label,
                'description'   => $k->description,
                'default_value' => $k->default_value,
                'sort_order'    => $k->sort_order,
            ])->all();
    }

    private function serializePluginConfigs(AiPreset $preset): array
    {
        // default_config is NOT exported — it is restored from the plugin's own
        // defaults on import. config_data is stripped of secret-looking keys.
        return $preset->pluginConfigurations()->get()->map(fn ($c) => [
            'plugin_name' => $c->plugin_name,
            'is_enabled'  => $c->is_enabled,
            'config_data' => $this->stripConfigSecrets($c->config_data ?? []),
        ])->all();
    }

    private function serializeCapabilityConfigs(AiPreset $preset): array
    {
        // TODO: if capability drivers gain getConfigFields() with type=password,
        //       replace stripConfigSecrets() with a declarative strip like
        //       stripSecrets() does for engines.
        return PresetCapabilityConfig::where('preset_id', $preset->getId())->get()
            ->map(fn ($c) => [
                'capability' => $c->capability,
                'driver'     => $c->driver,
                'is_active'  => $c->is_active,
                'config'     => $this->stripConfigSecrets($c->config ?? []),
            ])->all();
    }

    private function serializePluginData(AiPreset $preset): array
    {
        return PresetPluginData::where('preset_id', $preset->getId())
            ->orderBy('plugin_code')->orderBy('position')->orderBy('key')->get()
            ->map(fn ($d) => [
                'plugin_code' => $d->plugin_code,
                'key'         => $d->key,
                'value'       => $d->value,
                'position'    => $d->position,
            ])->all();
    }

    private function serializeBehaviorPatterns(AiPreset $preset): array
    {
        // Variant A: definitions only. Learned counters (fitness/confidence/
        // activation_count/last_activation_seq/triggered*) are OMITTED so DB
        // defaults zero them. status is reset to 'hypothesis' — must re-earn itself.
        return BehaviorPattern::where('preset_id', $preset->getId())->get()
            ->map(fn ($b) => [
                'name'                       => $b->name,
                'trigger'                    => $b->trigger,
                'intent'                     => $b->intent,
                'behavior'                   => $b->behavior,
                'constraints'                => $b->constraints,
                'lever'                      => $b->lever,
                'provenance'                 => $b->provenance,
                'priority'                   => $b->priority,
                'plasticity'                 => $b->plasticity,
                'immune'                     => $b->immune,
                'forced_activation_interval' => $b->forced_activation_interval,
                'status'                     => 'hypothesis',
            ])->all();
    }

    private function serializeContracts(AiPreset $preset): array
    {
        // Contracts live on raw SQL (hot path: contract:tick runs every minute,
        // so they deliberately avoid Eloquent hydration). Export is cold and
        // infrequent — read via query builder, no model needed.
        //
        // Variant A: definitions only. triggered/triggered_at/last_evaluated_at/
        // history OMITTED; status reset to 'hypothesis'.
        //
        // DB::table returns raw stdClass rows: JSON columns arrive as strings
        // (no casts apply) and tinyint/double as raw scalars — hence the explicit
        // decodeJson() and (bool)/(float) casts below.
        return $this->db->table('agent_contracts')
            ->where('preset_id', $preset->getId())
            ->get()
            ->map(fn ($c) => [
                'name'         => $c->name,
                'form'         => $c->form,
                'vital'        => (bool) $c->vital,
                'source'       => $c->source,
                'suspend_when' => $c->suspend_when,
                'confidence'   => (float) $c->confidence,
                'trigger'      => $this->decodeJson($c->trigger),
                'action'       => $this->decodeJson($c->action),
                'status'       => 'hypothesis',
            ])->all();
    }

    /**
     * Decode a raw JSON column value from a query-builder (stdClass) row.
     * DB::table returns raw strings — no Eloquent casts apply — so JSON columns
     * must be decoded explicitly. Null/empty stays null.
     */
    private function decodeJson(?string $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return json_decode($raw, true);
    }

    private function serializeRagConfigs(AiPreset $preset): array
    {
        return $preset->ragConfigs->map(fn ($r) => [
            'rag_preset_ref'             => $this->refFor($r->rag_preset_id),
            'sort_order'                 => $r->sort_order,
            'is_primary'                 => $r->is_primary,
            'context_mode'               => $r->context_mode,
            'sources'                    => $r->sources,
            'rag_mode'                   => $r->rag_mode,
            'rag_engine'                 => $r->rag_engine,
            'rag_context_limit'          => $r->rag_context_limit,
            'rag_results'                => $r->rag_results,
            'rag_journal_limit'          => $r->rag_journal_limit,
            'rag_skills_limit'           => $r->rag_skills_limit,
            'rag_content_limit'          => $r->rag_content_limit,
            'rag_journal_context_window' => $r->rag_journal_context_window,
            'rag_relative_dates'         => $r->rag_relative_dates,
        ])->all();
    }

    private function serializeInnerVoiceConfigs(AiPreset $preset): array
    {
        return $preset->innerVoiceConfigs->map(fn ($iv) => [
            'voice_preset_ref' => $this->refFor($iv->voice_preset_id),
            'sort_order'       => $iv->sort_order,
            'is_enabled'       => $iv->is_enabled,
            'context_limit'    => $iv->context_limit,
            'label'            => $iv->label,
        ])->all();
    }

    private function serializeSkills(AiPreset $preset): array
    {
        // tfidf_vector is NEVER exported — it is corpus-statistics of the source
        // instance and must be rebuilt on import.
        return Skill::where('preset_id', $preset->getId())
            ->orderBy('number')->get()
            ->map(fn ($skill) => [
                'title'       => $skill->title,
                'description' => $skill->description,
                'number'      => $skill->number,
                'items'       => $skill->items()->orderBy('number')->get()
                    ->map(fn ($item) => [
                        'number'  => $item->number,
                        'content' => $item->content,
                    ])->all(),
            ])->all();
    }

    // ── Serialization: agent ────────────────────────────────────────────────

    private function serializeAgent(Agent $agent): array
    {
        return [
            'name'               => $agent->name,
            'description'        => $agent->description,
            'code'               => $agent->code,
            'metadata'           => $agent->metadata,
            'planner_preset_ref' => $this->refFor($agent->planner_preset_id),
            'roles'              => $agent->roles->map(fn ($role) => [
                'code'                 => $role->code,
                'preset_ref'           => $this->refFor($role->preset_id),
                'validator_preset_ref' => $this->refFor($role->validator_preset_id),
                'max_attempts'         => $role->max_attempts,
                'auto_proceed'         => $role->auto_proceed,
            ])->all(),
        ];
    }

    // ── Secret stripping ────────────────────────────────────────────────────

    /**
     * Strip engine secrets by asking the engine which fields are declared as
     * `type: password`. Declarative, not a hardcoded key list — a new engine
     * with an access_token password field is cleaned automatically.
     *
     * Only what's physically in preset.engine_config is touched. We never call
     * the engine's getConfig()/getDefaultConfig(), which would pull the global
     * .env key into the export.
     */
    private function stripSecrets(array $engineConfig, string $engineName): array
    {
        try {
            $fields = $this->engineRegistry->getEngineConfigFields($engineName);
        } catch (\Throwable $e) {
            // Unknown engine on this instance — fall back to key-name stripping
            // so we never emit a secret just because we couldn't introspect.
            $this->logger->warning('PresetExporter: engine fields introspection failed, using key fallback', [
                'engine' => $engineName,
                'error'  => $e->getMessage(),
            ]);
            return $this->stripConfigSecrets($engineConfig);
        }

        foreach ($fields as $key => $meta) {
            if (($meta['type'] ?? '') === 'password' && array_key_exists($key, $engineConfig)) {
                $engineConfig[$key] = null; // explicit null = "enter manually"
            }
        }

        return $engineConfig;
    }

    /**
     * Fallback / capability secret stripping by key name. Used where no field
     * declaration is available (capability drivers, unknown engines).
     */
    private function stripConfigSecrets(array $config): array
    {
        foreach ($config as $key => $value) {
            foreach (self::CAPABILITY_SECRET_KEYS as $secretKey) {
                if (stripos((string) $key, $secretKey) !== false) {
                    $config[$key] = null;
                    break;
                }
            }
        }

        return $config;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Resolve a preset id to its bundle-local ref, or null.
     * Null when the id is null OR (defensively) points outside the closure —
     * the latter shouldn't happen since the closure is transitive.
     */
    private function refFor(?int $presetId): ?string
    {
        if ($presetId === null) {
            return null;
        }

        return $this->refMap[$presetId] ?? null;
    }

    /**
     * @param  int[] $agentIds
     * @return Agent[]
     */
    private function loadAgents(array $agentIds): array
    {
        if (empty($agentIds)) {
            return [];
        }

        $agents = $this->agentModel->with('roles')->findMany($agentIds);

        $missing = array_diff($agentIds, $agents->pluck('id')->all());
        if (!empty($missing)) {
            throw new ExportException('Agent(s) not found: ' . implode(', ', $missing));
        }

        return $agents->all();
    }
}

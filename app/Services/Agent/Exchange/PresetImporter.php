<?php

namespace App\Services\Agent\Exchange;

use App\Contracts\Agent\Exchange\PresetImporterInterface;
use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Orchestrator\AgentServiceInterface;
use App\Contracts\Agent\PluginManagerInterface;
use App\Contracts\Agent\Prompt\PresetPromptServiceInterface;
use App\Exceptions\Exchange\ImportException;
use App\Models\AiPreset;
use App\Models\BehaviorPattern;
use App\Models\PresetCapabilityConfig;
use App\Models\PresetKnownSource;
use App\Models\PresetPluginData;
use App\Models\PresetPromptVersion;
use App\Services\Agent\Capabilities\Embedding\EmbeddingRegistry;
use App\Services\Agent\Capabilities\Speech\SttRegistry;
use App\Services\Agent\Capabilities\Speech\TtsRegistry;
use App\Services\Agent\Capabilities\Vision\VisionRegistry;
use App\Services\Agent\Exchange\DTO\ImportResult;
use App\Services\Agent\Exchange\DTO\PreflightResult;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

/**
 * PresetImporter
 *
 * Reads a "depthnet.bundle" and recreates its presets and agents on this
 * instance. Orchestrates existing services rather than writing models directly
 * where a service exists (PresetService, PresetPromptService, PluginManager,
 * AgentService) — so all their validation, versioning and cache-refresh logic
 * runs. Only collections with no service (capabilities, known sources, plugin
 * data, behavior, contracts) are written directly, mirroring how the app itself
 * writes them.
 *
 * Guarantees:
 *  - preflight() writes nothing and accumulates ALL problems (errors + warnings).
 *  - import() runs entirely inside ONE transaction. Any failure — including a
 *    service returning success:false — throws and rolls everything back. No
 *    half-written state.
 *  - fail-closed: any preflight ERROR blocks the whole import. Missing optional
 *    capability drivers are WARNINGS, not errors.
 *  - Secrets arrive null by design; a null key is never treated as an error.
 *
 * @see PresetImporterInterface
 */
class PresetImporter implements PresetImporterInterface
{
    private const SUPPORTED_FORMAT         = 'depthnet.bundle';
    private const SUPPORTED_FORMAT_VERSION = 1;

    /** ref => created preset id, filled during phase 1. */
    private array $refMap = [];

    public function __construct(
        protected PresetServiceInterface $presetService,
        protected PresetPromptServiceInterface $promptService,
        protected PluginManagerInterface $pluginManager,
        protected PresetRegistryInterface $presetRegistry,
        protected EngineRegistryInterface $engineRegistry,
        protected AgentServiceInterface $agentService,
        protected AiPreset $presetModel,
        protected EmbeddingRegistry $embeddingRegistry,
        protected VisionRegistry $visionRegistry,
        protected SttRegistry $sttRegistry,
        protected TtsRegistry $ttsRegistry,
        protected DatabaseManager $db,
        protected LoggerInterface $logger,
    ) {
    }

    // ── Preflight (no writes, accumulates everything) ───────────────────────

    /**
     * @inheritDoc
     */
    public function preflight(array $bundle): PreflightResult
    {
        $r = new PreflightResult();

        // — envelope —
        if (($bundle['format'] ?? null) !== self::SUPPORTED_FORMAT) {
            $r->addError("Unrecognized bundle format. Expected '" . self::SUPPORTED_FORMAT . "'.");
            return $r; // nothing else is trustworthy
        }

        $version = $bundle['format_version'] ?? null;
        $r->formatVersion = is_int($version) ? $version : null;

        if (!is_int($version) || $version > self::SUPPORTED_FORMAT_VERSION) {
            $r->addError(
                "Unsupported format_version '" . var_export($version, true) . "'. " .
                "This instance understands up to v" . self::SUPPORTED_FORMAT_VERSION . "."
            );
            return $r; // future format — don't guess its shape
        }

        $presets = $bundle['presets'] ?? [];
        $agents  = $bundle['agents']  ?? [];
        $r->presetCount = count($presets);
        $r->agentCount  = count($agents);

        if (empty($presets) && empty($agents)) {
            $r->addError('Bundle contains no presets and no agents.');
            return $r;
        }

        // — collect all refs present in the bundle —
        $presentRefs = [];
        foreach ($presets as $p) {
            if (!empty($p['ref'])) {
                $presentRefs[$p['ref']] = true;
            }
        }

        // — per-preset checks —
        $seenPresetCodes = [];
        foreach ($presets as $i => $p) {
            $label = $this->presetLabel($p, $i);

            // ref presence
            if (empty($p['ref'])) {
                $r->addError("{$label}: missing 'ref'.");
            }

            // engine must exist on this instance (load-bearing → error)
            $engine = $p['engine_name'] ?? null;
            if (empty($engine)) {
                $r->addError("{$label}: missing engine_name.");
            } elseif (!$this->engineRegistry->has($engine)) {
                $r->addError("{$label}: engine '{$engine}' is not available on this instance.");
            }

            // exactly one active prompt
            $this->checkActivePrompt($p, $label, $r);

            // internal *_ref targets must be inside the bundle
            $this->checkRef($p['cycle_prompt_preset_ref'] ?? null, $presentRefs, "{$label}: cycle_prompt_preset_ref", $r);
            $this->checkRef($p['target_preset_ref'] ?? null, $presentRefs, "{$label}: target_preset_ref", $r);

            foreach (($p['rag_configs'] ?? []) as $j => $rag) {
                $this->checkRef($rag['rag_preset_ref'] ?? null, $presentRefs, "{$label}: rag_configs[{$j}].rag_preset_ref", $r, required: true);
            }
            foreach (($p['inner_voice_configs'] ?? []) as $j => $iv) {
                $this->checkRef($iv['voice_preset_ref'] ?? null, $presentRefs, "{$label}: inner_voice_configs[{$j}].voice_preset_ref", $r, required: true);
            }

            // capability drivers: optional → WARNING if the driver is gone
            foreach (($p['capability_configs'] ?? []) as $cap) {
                $this->checkCapabilityDriver($cap, $label, $r);
            }

            // preset_code collision (load-bearing identifier → error)
            $code = $p['preset_code'] ?? null;
            if (!empty($code)) {
                if (isset($seenPresetCodes[$code])) {
                    $r->addError("preset_code '{$code}' appears more than once in the bundle.");
                }
                $seenPresetCodes[$code] = true;
            }
        }

        // — code collisions against the DB (one query each) —
        $this->checkPresetCodeCollisions($seenPresetCodes, $r);

        // — agent checks —
        $seenAgentCodes = [];
        foreach ($agents as $i => $a) {
            $label = "agent '" . ($a['name'] ?? "#{$i}") . "'";

            $this->checkRef($a['planner_preset_ref'] ?? null, $presentRefs, "{$label}: planner_preset_ref", $r, required: true);

            foreach (($a['roles'] ?? []) as $j => $role) {
                $this->checkRef($role['preset_ref'] ?? null, $presentRefs, "{$label}: roles[{$j}].preset_ref", $r, required: true);
                // validator is optional — only check when present
                if (!empty($role['validator_preset_ref'])) {
                    $this->checkRef($role['validator_preset_ref'], $presentRefs, "{$label}: roles[{$j}].validator_preset_ref", $r);
                }
            }

            $code = $a['code'] ?? null;
            if (!empty($code)) {
                if (isset($seenAgentCodes[$code])) {
                    $r->addError("agent code '{$code}' appears more than once in the bundle.");
                }
                $seenAgentCodes[$code] = true;
            }
        }

        $this->checkAgentCodeCollisions($seenAgentCodes, $r);

        return $r;
    }

    // ── Import (single transaction) ─────────────────────────────────────────

    /**
     * @inheritDoc
     */
    public function import(array $bundle, ?int $createdBy = null): ImportResult
    {
        $pre = $this->preflight($bundle);

        if ($pre->hasErrors()) {
            throw new ImportException(
                'Import blocked: ' . count($pre->errors) . ' error(s).',
                $pre->errors
            );
        }

        $this->refMap = [];
        $includeSkills = (bool) ($bundle['options']['include_skills'] ?? false);

        $result = new ImportResult();
        $result->warnings = $pre->warnings;

        // ONE transaction over both phases. PresetService::createPreset opens its
        // own nested transaction (savepoint) — fine; a throw anywhere unwinds the lot.
        $this->db->transaction(function () use ($bundle, $createdBy, $includeSkills, $result) {

            // ── Phase 1: create every preset WITHOUT self-links ──
            foreach (($bundle['presets'] ?? []) as $p) {
                $preset = $this->createBarePreset($p, $createdBy);
                $this->refMap[$p['ref']] = $preset->getId();
                $result->presetIds[] = $preset->getId();
            }

            // ── Phase 2: resolve links + owned collections ──
            foreach (($bundle['presets'] ?? []) as $p) {
                $presetId = $this->refMap[$p['ref']];
                $preset   = $this->presetModel->findOrFail($presetId);

                $this->applySelfLinks($preset, $p);
                $this->importPrompts($preset, $p, $createdBy);
                $this->importKnownSources($preset, $p);
                $this->importPluginConfigs($preset, $p);
                $this->importCapabilityConfigs($preset, $p);
                $this->importPluginData($preset, $p);
                $this->importBehaviorPatterns($preset, $p);
                $this->importContracts($preset, $p);
                $this->importRagConfigs($preset, $p);
                $this->importInnerVoiceConfigs($preset, $p);

                if ($includeSkills) {
                    $this->importSkills($preset, $p);
                }
            }

            // ── Agents (after all presets exist so refs resolve) ──
            foreach (($bundle['agents'] ?? []) as $a) {
                $result->agentIds[] = $this->importAgent($a, $createdBy);
            }
        });

        // Cache must be refreshed or imported presets won't appear until TTL.
        // PresetService::createPreset already refreshes, but agents/collections
        // were written after — refresh once more to be safe.
        $this->presetRegistry->refresh();

        $this->logger->info('PresetImporter: import complete', [
            'presets'  => count($result->presetIds),
            'agents'   => count($result->agentIds),
            'warnings' => count($result->warnings),
        ]);

        return $result;
    }

    // ── Phase 1: bare preset ────────────────────────────────────────────────

    /**
     * Create a preset with everything EXCEPT self-referential links and the
     * prompts array (prompts are created in phase 2 via PresetPromptService so
     * they get a legitimate v1). Self-links are null here — resolved in phase 2.
     */
    private function createBarePreset(array $p, ?int $createdBy): AiPreset
    {
        $data = [
            // scalars straight from the bundle (whitelist mirrors the exporter)
            'name'                       => $this->uniqueName($p['name']),
            'description'                => $p['description'] ?? null,
            'engine_name'                => $p['engine_name'],
            'engine_config'              => $p['engine_config'] ?? [],
            'input_mode'                 => $p['input_mode'] ?? 'single',
            'pool_relative_dates'        => $p['pool_relative_dates'] ?? false,
            'pulse_dates'                => $p['pulse_dates'] ?? false,
            'agent_result_mode'          => $p['agent_result_mode'] ?? 'tool_calls',
            'max_context_limit'          => $p['max_context_limit'] ?? 8,
            'max_context_limit_extended' => $p['max_context_limit_extended'] ?? null,
            'pre_pass_enabled'           => $p['pre_pass_enabled'] ?? false,
            'pre_pass_instruction'       => $p['pre_pass_instruction'] ?? null,
            'loop_interval'              => $p['loop_interval'] ?? 15,
            'before_execution_wait'      => $p['before_execution_wait'] ?? 5,
            'error_behavior'             => $p['error_behavior'] ?? 'stop',
            'allow_handoff_to'           => $p['allow_handoff_to'] ?? true,
            'allow_handoff_from'         => $p['allow_handoff_from'] ?? true,
            'preset_code'                => $p['preset_code'] ?? null,
            'preset_code_next'           => $p['preset_code_next'] ?? null,
            'plugins_disabled'           => $p['plugins_disabled'] ?? '',
            'pre_run_commands'           => $p['pre_run_commands'] ?? '',
            'turn_trigger'               => $p['turn_trigger'] ?? 'none',
            'defrag_enabled'             => $p['defrag_enabled'] ?? false,
            'defrag_prompt'              => $p['defrag_prompt'] ?? null,
            'defrag_keep_per_day'        => $p['defrag_keep_per_day'] ?? 3,
            'cp_context_limit'           => $p['cp_context_limit'] ?? 5,
            'voice_mp_commands'          => $p['voice_mp_commands'] ?? '',
            'default_call_message'       => $p['default_call_message'] ?? null,
            'metadata'                   => $p['metadata'] ?? [],
            'target_plugins_whitelist'   => $p['target_plugins_whitelist'] ?? null,
            'rhasspy_enabled'            => $p['rhasspy_enabled'] ?? false,
            'rhasspy_url'                => $p['rhasspy_url'] ?? null,
            'rhasspy_tts_voice'          => $p['rhasspy_tts_voice'] ?? null,
            'rhasspy_incoming_enabled'   => $p['rhasspy_incoming_enabled'] ?? false,

            // imported presets are never default; active by default
            'is_active'                  => true,
            'is_default'                 => false,

            // self-links deferred to phase 2
            'cycle_prompt_preset_id'     => null,
            'target_preset_id'           => null,

            'created_by'                 => $createdBy,

            // Suppress PresetService's legacy auto-prompt: we pass an empty prompts
            // array so it creates a placeholder, then phase 2 adds the real prompts
            // and sets the active one. (See importPrompts.)
            'prompts'                    => [],
        ];

        // PresetService::createPreset validates, initializes plugin configs and
        // refreshes the registry. skipSecretValidation=true tells it not to reject
        // the preset just because password fields (api_key etc.) are null — they
        // were stripped at export and the user enters them via the UI after import.
        // Range validation (temperature, max_tokens) still runs.
        return $this->presetService->createPreset($data, skipSecretValidation: true);
    }

    // ── Phase 2 steps ───────────────────────────────────────────────────────

    private function applySelfLinks(AiPreset $preset, array $p): void
    {
        $cycle  = $this->resolveRef($p['cycle_prompt_preset_ref'] ?? null);
        $target = $this->resolveRef($p['target_preset_ref'] ?? null);

        if ($cycle === null && $target === null) {
            return;
        }

        $preset->cycle_prompt_preset_id = $cycle;
        $preset->target_preset_id       = $target;
        $preset->saveQuietly();
    }

    /**
     * Create prompts via PresetPromptService so each gets a legitimate v1
     * "Initial version". Sets the active prompt from the bundle's is_active flag.
     *
     * createBarePreset passed prompts:[] which makes PresetService create one
     * placeholder 'default' prompt. We reconcile: if the bundle has its own
     * 'default', we update the placeholder instead of creating a duplicate code.
     */
    private function importPrompts(AiPreset $preset, array $p, ?int $createdBy): void
    {
        $bundlePrompts = $p['prompts'] ?? [];
        if (empty($bundlePrompts)) {
            return; // placeholder from PresetService stands
        }

        $activePromptId = null;

        // The placeholder prompt PresetService created (code 'default'), if any.
        $placeholder = $preset->prompts()->first();
        $placeholderUsed = false;

        foreach ($bundlePrompts as $bp) {
            $code    = $bp['code'] ?? 'default';
            $content = $bp['content'] ?? '';
            $desc    = $bp['description'] ?? null;

            if ($placeholder && !$placeholderUsed && $placeholder->code === $code) {
                // Reuse the auto-created placeholder for the matching code so we
                // don't hit the unique(preset_id, code) constraint.
                $prompt = $this->promptService->update(
                    $preset,
                    $placeholder->getId(),
                    ['content' => $content, 'description' => $desc, 'edit_summary' => 'Imported'],
                    PresetPromptVersion::BY_SYSTEM,
                    $createdBy
                );
                $placeholderUsed = true;
            } else {
                $prompt = $this->promptService->create(
                    $preset,
                    ['code' => $code, 'content' => $content, 'description' => $desc],
                    false,
                    PresetPromptVersion::BY_SYSTEM,
                    $createdBy
                );
            }

            if (!empty($bp['is_active'])) {
                $activePromptId = $prompt->getId();
            }
        }

        if ($activePromptId !== null) {
            $this->promptService->setActive($preset, $activePromptId);
        }
    }

    private function importKnownSources(AiPreset $preset, array $p): void
    {
        foreach (($p['known_sources'] ?? []) as $ks) {
            PresetKnownSource::create([
                'preset_id'     => $preset->getId(),
                'source_name'   => $ks['source_name'],
                'label'         => $ks['label'],
                'description'   => $ks['description'] ?? null,
                'default_value' => $ks['default_value'] ?? null,
                'sort_order'    => $ks['sort_order'] ?? 0,
            ]);
        }
    }

    /**
     * Apply plugin configs from the bundle ON TOP of the defaults that
     * PresetService::createPreset already materialized (initializeConfigsForPreset).
     * updatePluginConfigForPreset runs the plugin's own validateConfig — a bad
     * config from a foreign bundle surfaces there and throws, rolling back.
     */
    private function importPluginConfigs(AiPreset $preset, array $p): void
    {
        foreach (($p['plugin_configs'] ?? []) as $pc) {
            $name = $pc['plugin_name'] ?? null;
            if ($name === null) {
                continue;
            }

            $res = $this->pluginManager->updatePluginConfigForPreset(
                $name,
                $preset,
                $pc['config_data'] ?? []
            );

            // Unknown plugin on this instance: not fatal (plugin may be a composer
            // package that isn't installed). Log and continue — the config simply
            // isn't applied. The default (from init) remains.
            if (!($res['success'] ?? false)) {
                $this->logger->warning('PresetImporter: plugin config skipped', [
                    'preset_id' => $preset->getId(),
                    'plugin'    => $name,
                    'errors'    => $res['errors'] ?? null,
                ]);
                continue;
            }

            // Preserve the enabled flag from the bundle.
            if (array_key_exists('is_enabled', $pc)) {
                $this->pluginManager->setPluginEnabledForPreset($name, $preset, (bool) $pc['is_enabled']);
            }
        }
    }

    private function importCapabilityConfigs(AiPreset $preset, array $p): void
    {
        // Written directly (no service). No validateConfig here — configs carry
        // null secrets by design and would fail a required-key check. The driver
        // may even be absent (a preflight WARNING); we still store the config so
        // the user can install the driver and it works, or re-point it in the UI.
        foreach (($p['capability_configs'] ?? []) as $cap) {
            PresetCapabilityConfig::updateOrCreate(
                ['preset_id' => $preset->getId(), 'capability' => $cap['capability']],
                [
                    'driver'    => $cap['driver'],
                    'config'    => $cap['config'] ?? [],
                    'is_active' => $cap['is_active'] ?? true,
                ]
            );
        }
    }

    private function importPluginData(AiPreset $preset, array $p): void
    {
        foreach (($p['plugin_data'] ?? []) as $d) {
            PresetPluginData::create([
                'preset_id'   => $preset->getId(),
                'plugin_code' => $d['plugin_code'],
                'key'         => $d['key'],
                'value'       => $d['value'] ?? null,
                'position'    => $d['position'] ?? 0,
            ]);
        }
    }

    private function importBehaviorPatterns(AiPreset $preset, array $p): void
    {
        // Variant A: definitions only. status forced to 'hypothesis'; learned
        // counters left at their column defaults (0).
        foreach (($p['behavior_patterns'] ?? []) as $b) {
            BehaviorPattern::create([
                'preset_id'                  => $preset->getId(),
                'name'                       => $b['name'],
                'trigger'                    => $b['trigger'] ?? [],
                'intent'                     => $b['intent'] ?? null,
                'behavior'                   => $b['behavior'] ?? null,
                'constraints'                => $b['constraints'] ?? null,
                'lever'                      => $b['lever'] ?? null,
                'provenance'                 => $b['provenance'] ?? 'architect',
                'priority'                   => $b['priority'] ?? 1.0,
                'plasticity'                 => $b['plasticity'] ?? 1.0,
                'immune'                     => $b['immune'] ?? false,
                'forced_activation_interval' => $b['forced_activation_interval'] ?? null,
                'status'                     => 'hypothesis',
            ]);
        }
    }

    private function importContracts(AiPreset $preset, array $p): void
    {
        // Contracts live on raw SQL (hot path). Insert via query builder, encoding
        // JSON columns explicitly. Variant A: status 'hypothesis', no history.
        $now = now();
        foreach (($p['contracts'] ?? []) as $c) {
            $this->db->table('agent_contracts')->insert([
                'preset_id'    => $preset->getId(),
                'name'         => $c['name'],
                'form'         => $c['form'],
                'status'       => 'hypothesis',
                'vital'        => (bool) ($c['vital'] ?? false),
                'source'       => $c['source'] ?? 'engine',
                'suspend_when' => $c['suspend_when'] ?? null,
                'confidence'   => (float) ($c['confidence'] ?? 1.0),
                'triggered'    => false,
                'trigger'      => json_encode($c['trigger'] ?? [], JSON_UNESCAPED_UNICODE),
                'action'       => json_encode($c['action'] ?? [], JSON_UNESCAPED_UNICODE),
                'history'      => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    private function importRagConfigs(AiPreset $preset, array $p): void
    {
        foreach (($p['rag_configs'] ?? []) as $rag) {
            $ragPresetId = $this->resolveRef($rag['rag_preset_ref'] ?? null);
            if ($ragPresetId === null) {
                // preflight already guaranteed this ref resolves; defensive skip.
                continue;
            }

            $preset->ragConfigs()->create([
                'rag_preset_id'              => $ragPresetId,
                'sort_order'                 => $rag['sort_order'] ?? 0,
                'is_primary'                 => $rag['is_primary'] ?? false,
                'context_mode'               => $rag['context_mode'] ?? 'both',
                'sources'                    => $rag['sources'] ?? [],
                'rag_mode'                   => $rag['rag_mode'] ?? 'flat',
                'rag_engine'                 => $rag['rag_engine'] ?? 'tfidf',
                'rag_context_limit'          => $rag['rag_context_limit'] ?? 5,
                'rag_results'                => $rag['rag_results'] ?? 5,
                'rag_journal_limit'          => $rag['rag_journal_limit'] ?? 3,
                'rag_skills_limit'           => $rag['rag_skills_limit'] ?? 3,
                'rag_content_limit'          => $rag['rag_content_limit'] ?? 400,
                'rag_journal_context_window' => $rag['rag_journal_context_window'] ?? 0,
                'rag_relative_dates'         => $rag['rag_relative_dates'] ?? false,
            ]);
        }
    }

    private function importInnerVoiceConfigs(AiPreset $preset, array $p): void
    {
        foreach (($p['inner_voice_configs'] ?? []) as $iv) {
            $voiceId = $this->resolveRef($iv['voice_preset_ref'] ?? null);
            if ($voiceId === null) {
                continue;
            }

            $preset->innerVoiceConfigs()->create([
                'voice_preset_id' => $voiceId,
                'sort_order'      => $iv['sort_order'] ?? 0,
                'is_enabled'      => $iv['is_enabled'] ?? true,
                'context_limit'   => $iv['context_limit'] ?? 10,
                'label'           => $iv['label'] ?? null,
            ]);
        }
    }

    private function importSkills(AiPreset $preset, array $p): void
    {
        // tfidf_vector intentionally null — rebuilt by the skill indexer.
        foreach (($p['skills'] ?? []) as $skill) {
            $created = $this->db->table('agent_skills')->insertGetId([
                'preset_id'   => $preset->getId(),
                'title'       => $skill['title'],
                'description' => $skill['description'] ?? null,
                'number'      => $skill['number'],
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            foreach (($skill['items'] ?? []) as $item) {
                $this->db->table('agent_skill_items')->insert([
                    'skill_id'     => $created,
                    'number'       => $item['number'],
                    'content'      => $item['content'],
                    'tfidf_vector' => null,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }
        }
    }

    // ── Agents ──────────────────────────────────────────────────────────────

    /**
     * Create an agent and its roles via AgentService. The service returns
     * ['success' => bool, ...] instead of throwing — so on failure we throw
     * ourselves to roll back the whole import transaction.
     *
     * @return int Created agent id.
     */
    private function importAgent(array $a, ?int $createdBy): int
    {
        $plannerId = $this->resolveRef($a['planner_preset_ref'] ?? null);
        if ($plannerId === null) {
            throw new ImportException("Agent '{$a['name']}': planner preset ref did not resolve.");
        }

        $res = $this->agentService->createAgent(
            name:            $a['name'],
            plannerPresetId: $plannerId,
            code:            $a['code'] ?? null,
            description:     $a['description'] ?? null,
            isActive:        true,
            createdBy:       $createdBy
        );

        if (!($res['success'] ?? false)) {
            throw new ImportException("Agent '{$a['name']}' creation failed: {$res['message']}");
        }

        /** @var \App\Models\Agent $agent */
        $agent = $res['agent'];

        foreach (($a['roles'] ?? []) as $role) {
            $presetId    = $this->resolveRef($role['preset_ref'] ?? null);
            $validatorId = $this->resolveRef($role['validator_preset_ref'] ?? null);

            if ($presetId === null) {
                throw new ImportException(
                    "Agent '{$a['name']}', role '{$role['code']}': preset ref did not resolve."
                );
            }

            $roleRes = $this->agentService->addRole(
                agent:             $agent,
                code:              $role['code'],
                presetId:          $presetId,
                validatorPresetId: $validatorId,
                maxAttempts:       $role['max_attempts'] ?? 3,
                autoProceed:       $role['auto_proceed'] ?? false
            );

            if (!($roleRes['success'] ?? false)) {
                throw new ImportException(
                    "Agent '{$a['name']}', role '{$role['code']}' failed: {$roleRes['message']}"
                );
            }
        }

        return $agent->getId();
    }

    // ── Preflight helpers ───────────────────────────────────────────────────

    private function presetLabel(array $p, int $i): string
    {
        $name = $p['name'] ?? "#{$i}";
        return "preset '{$name}'";
    }

    private function checkActivePrompt(array $p, string $label, PreflightResult $r): void
    {
        $prompts = $p['prompts'] ?? [];
        if (empty($prompts)) {
            $r->addError("{$label}: has no prompts.");
            return;
        }

        $activeCount = 0;
        foreach ($prompts as $bp) {
            if (!empty($bp['is_active'])) {
                $activeCount++;
            }
        }

        if ($activeCount !== 1) {
            $r->addError("{$label}: must have exactly one active prompt, found {$activeCount}.");
        }
    }

    /**
     * A *_ref must resolve to a preset present in the bundle. When $required,
     * a null/empty ref is itself an error; otherwise null is allowed (no link).
     */
    private function checkRef(?string $ref, array $presentRefs, string $where, PreflightResult $r, bool $required = false): void
    {
        if ($ref === null || $ref === '') {
            if ($required) {
                $r->addError("{$where}: reference is missing.");
            }
            return;
        }

        if (!isset($presentRefs[$ref])) {
            $r->addError("{$where}: points to '{$ref}' which is not present in the bundle.");
        }
    }

    private function checkCapabilityDriver(array $cap, string $label, PreflightResult $r): void
    {
        $capability = $cap['capability'] ?? null;
        $driver     = $cap['driver']     ?? null;
        if ($capability === null || $driver === null) {
            return;
        }

        $registry = match ($capability) {
            'embedding' => $this->embeddingRegistry,
            'vision'    => $this->visionRegistry,
            'stt'       => $this->sttRegistry,
            'tts'       => $this->ttsRegistry,
            default     => null,
        };

        if ($registry === null) {
            $r->addWarning("{$label}: unknown capability '{$capability}' — config imported but may be inert.");
            return;
        }

        if (!$registry->has($driver)) {
            $r->addWarning(
                "{$label} / capability {$capability}: driver '{$driver}' is not registered on this instance " .
                "— config imported but inactive; install the driver or change it in the UI."
            );
        }
    }

    private function checkPresetCodeCollisions(array $codes, PreflightResult $r): void
    {
        $codes = array_keys($codes);
        if (empty($codes)) {
            return;
        }

        $taken = $this->presetModel->whereIn('preset_code', $codes)->pluck('preset_code');
        foreach ($taken as $code) {
            $r->addError("preset_code '{$code}' already exists on this instance.");
        }
    }

    private function checkAgentCodeCollisions(array $codes, PreflightResult $r): void
    {
        $codes = array_keys($codes);
        if (empty($codes)) {
            return;
        }

        $taken = $this->db->table('agents')->whereIn('code', $codes)->pluck('code');
        foreach ($taken as $code) {
            $r->addError("agent code '{$code}' already exists on this instance.");
        }
    }

    // ── ref resolution ──────────────────────────────────────────────────────

    private function resolveRef(?string $ref): ?int
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        return $this->refMap[$ref] ?? null;
    }

    /**
     * Resolve a preset name that is free on this instance.
     *
     * name is a cosmetic label — nothing references it programmatically — so a
     * collision is resolved by suffixing, NOT by blocking the import (unlike
     * preset_code, which is a functional identifier and blocks in preflight).
     * Mirrors duplicatePreset's "(Copy)" / "(N)" strategy.
     */
    private function uniqueName(string $name): string
    {
        $name = trim($name);

        if (!$this->presetModel->where('name', $name)->exists()) {
            return $name;
        }

        $base      = $name . ' (imported)';
        $candidate = $base;
        $i         = 1;

        while ($this->presetModel->where('name', $candidate)->exists()) {
            $candidate = "{$base} ({$i})";
            $i++;
        }

        return $candidate;
    }
}

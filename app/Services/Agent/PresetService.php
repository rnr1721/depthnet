<?php

namespace App\Services\Agent;

use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\PluginManagerFactoryInterface;
use App\Contracts\Agent\PresetPromptServiceInterface;
use App\Contracts\Auth\AuthServiceInterface;
use App\Models\AiPreset;
use App\Exceptions\PresetException;
use App\Models\PresetPromptVersion;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\ValidationException;
use Illuminate\Cache\CacheManager;
use Psr\Log\LoggerInterface;

/**
 * Enhanced service for managing AI presets with validation, logging and auth
 */
class PresetService implements PresetServiceInterface
{
    public function __construct(
        protected EngineRegistryInterface $engineRegistry,
        protected PresetRegistryInterface $presetRegistry,
        protected AuthServiceInterface $authService,
        protected DatabaseManager $db,
        protected ValidatorFactory $validator,
        protected AiPreset $aiPresetModel,
        protected LoggerInterface $logger,
        protected CacheManager $cacheManager,
        protected PluginManagerFactoryInterface $pluginManagerFactory,
        protected PresetPromptServiceInterface $promptService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function createPreset(array $data, bool $skipSecretValidation = false): AiPreset
    {
        $this->validatePresetData($data, null, $skipSecretValidation);

        return $this->db->transaction(function () use ($data) {
            $preset = $this->aiPresetModel->create([
                'target_preset_id'         => $data['target_preset_id'] ?? null,
                'target_plugins_whitelist' => $data['target_plugins_whitelist'] ?? null,
                'parent_preset_id' => $data['parent_preset_id'] ?? null,
                'is_spawned'       => $data['is_spawned'] ?? false,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'engine_name' => $data['engine_name'],
                'input_mode' => $data['input_mode'] ?? 'single',
                'pool_relative_dates' => $data['pool_relative_dates'] ?? false,
                'pulse_dates' => $data['pulse_dates'] ?? false,
                'preset_code' => $data['preset_code'] ?? null,
                'plugins_disabled' => $data['plugins_disabled'] ?? '',
                'engine_config' => $data['engine_config'] ?? [],
                'loop_interval' => $data['loop_interval'] ?? 15,
                'max_context_limit' => $data['max_context_limit'] ?? 8,
                'max_context_limit_extended' => $data['max_context_limit_extended'] ?? null,
                'pre_pass_enabled'           => $data['pre_pass_enabled'] ?? false,
                'pre_pass_instruction'       => $data['pre_pass_instruction'] ?? null,
                'agent_result_mode' => $data['agent_result_mode'] ?? 'tool_calls',
                'preset_code_next' => $data['preset_code_next'] ?? '',
                'pre_run_commands' => $data['pre_run_commands'] ?? '',
                'turn_trigger' => $data['turn_trigger'] ?? 'none',
                'defrag_enabled'      => $data['defrag_enabled'] ?? false,
                'defrag_prompt'       => $data['defrag_prompt'] ?? null,
                'defrag_keep_per_day' => $data['defrag_keep_per_day'] ?? 3,
                'cycle_prompt_preset_id' => $data['cycle_prompt_preset_id'] ?? null,
                'cp_context_limit' => $data['cp_context_limit'] ?? null,
                'voice_mp_commands' => $data['voice_mp_commands'] ?? '',
                'default_call_message' => $data['default_call_message'] ?? '',
                'before_execution_wait' => $data['before_execution_wait'] ?? 5,
                'error_behavior' => $data['error_behavior'] ?? 'stop',
                'allow_handoff_to' => $data['allow_handoff_to'] ?? true,
                'allow_handoff_from' => $data['allow_handoff_from'] ?? true,
                'rhasspy_enabled'          => $data['rhasspy_enabled'] ?? false,
                'rhasspy_url'              => $data['rhasspy_url'] ?? null,
                'rhasspy_tts_voice'        => $data['rhasspy_tts_voice'] ?? null,
                'rhasspy_incoming_enabled' => $data['rhasspy_incoming_enabled'] ?? false,
                'rhasspy_incoming_token'   => $data['rhasspy_incoming_token'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? false,
                'created_by' => $data['created_by'] ?? $this->authService->getCurrentUserId(),
            ]);

            // Sync prompts (create initial 'default' prompt or process prompts array)
            $this->syncPrompts($preset, $data);

            // If this is set as default, ensure only one default exists
            if ($preset->is_default) {
                $this->setDefaultPreset($preset->id);
            }

            $this->presetRegistry->refresh();

            $this->pluginManagerFactory->get()->initializeConfigsForPreset($preset);

            $this->logPresetCreated($preset);

            return $preset;
        });
    }

    /**
     * @inheritDoc
     */
    public function createPresetWithValidation(array $data): AiPreset
    {
        // Enhanced validation with engine config
        $this->validateEngineConfigData($data['engine_name'], $data['engine_config']);

        $data['created_by'] = $this->authService->getCurrentUserId();

        $preset = $this->createPreset($data);

        return $preset;
    }

    /**
     * @inheritDoc
     */
    public function updatePreset(int $id, array $data): AiPreset
    {
        $preset = $this->aiPresetModel->findOrFail($id);

        $this->validatePresetData($data, $id);

        return $this->db->transaction(function () use ($preset, $data) {
            $preset->update([
                'target_preset_id'         => array_key_exists('target_preset_id', $data) ? $data['target_preset_id'] : $preset->target_preset_id,
                'target_plugins_whitelist' => array_key_exists('target_plugins_whitelist', $data) ? $data['target_plugins_whitelist'] : $preset->target_plugins_whitelist,
                'parent_preset_id' => array_key_exists('parent_preset_id', $data) ? $data['parent_preset_id'] : $preset->parent_preset_id,
                'is_spawned'       => array_key_exists('is_spawned', $data) ? $data['is_spawned'] : $preset->is_spawned,
                'name' => $data['name'] ?? $preset->name,
                'description' => $data['description'] ?? $preset->description,
                'engine_name' => $data['engine_name'] ?? $preset->engine_name,
                'input_mode' => array_key_exists('input_mode', $data) ? $data['input_mode'] : $preset->input_mode,
                'pool_relative_dates' => array_key_exists('pool_relative_dates', $data) ? $data['pool_relative_dates'] : $preset->pool_relative_dates,
                'pulse_dates' => array_key_exists('pulse_dates', $data) ? $data['pulse_dates'] : $preset->pulse_dates,
                'preset_code' => array_key_exists('preset_code', $data) ? $data['preset_code'] : $preset->preset_code,
                'plugins_disabled' => array_key_exists('plugins_disabled', $data) ? $data['plugins_disabled'] : $preset->plugins_disabled,
                'engine_config' => $data['engine_config'] ?? $preset->engine_config,
                'loop_interval' => $data['loop_interval'] ?? $preset->loop_interval,
                'max_context_limit' => $data['max_context_limit'] ?? $preset->max_context_limit,
                'max_context_limit_extended' => array_key_exists('max_context_limit_extended', $data) ? $data['max_context_limit_extended'] : $preset->max_context_limit_extended,
                'pre_pass_enabled'           => array_key_exists('pre_pass_enabled', $data) ? $data['pre_pass_enabled'] : $preset->pre_pass_enabled,
                'pre_pass_instruction'       => array_key_exists('pre_pass_instruction', $data) ? $data['pre_pass_instruction'] : $preset->pre_pass_instruction,
                'agent_result_mode' => $data['agent_result_mode'] ?? $preset->agent_result_mode,
                'preset_code_next' => array_key_exists('preset_code_next', $data) ? $data['preset_code_next'] : $preset->preset_code_next,
                'pre_run_commands' => array_key_exists('pre_run_commands', $data) ? $data['pre_run_commands'] : $preset->pre_run_commands,
                'turn_trigger' => array_key_exists('turn_trigger', $data) ? $data['turn_trigger'] : $preset->turn_trigger,
                'defrag_enabled'      => array_key_exists('defrag_enabled', $data) ? $data['defrag_enabled'] : $preset->defrag_enabled,
                'defrag_prompt'       => array_key_exists('defrag_prompt', $data) ? $data['defrag_prompt'] : $preset->defrag_prompt,
                'defrag_keep_per_day' => array_key_exists('defrag_keep_per_day', $data) ? $data['defrag_keep_per_day'] : $preset->defrag_keep_per_day,
                'cycle_prompt_preset_id' => array_key_exists('cycle_prompt_preset_id', $data) ? $data['cycle_prompt_preset_id'] : $preset->cycle_prompt_preset_id,
                'cp_context_limit' => array_key_exists('cp_context_limit', $data) ? $data['cp_context_limit'] : $preset->cp_context_limit,
                'voice_mp_commands' => array_key_exists('voice_mp_commands', $data) ? $data['voice_mp_commands'] : $preset->voice_mp_commands,
                'default_call_message' => array_key_exists('default_call_message', $data) ? $data['default_call_message'] : $preset->default_call_message,
                'before_execution_wait' => $data['before_execution_wait'] ?? $preset->before_execution_wait,
                'error_behavior' => $data['error_behavior'] ?? $preset->error_behavior,
                'allow_handoff_to' => $data['allow_handoff_to'] ?? $preset->allow_handoff_to,
                'allow_handoff_from' => $data['allow_handoff_from'] ?? $preset->allow_handoff_from,
                'rhasspy_enabled'          => array_key_exists('rhasspy_enabled', $data) ? $data['rhasspy_enabled'] : $preset->rhasspy_enabled,
                'rhasspy_url'              => array_key_exists('rhasspy_url', $data) ? $data['rhasspy_url'] : $preset->rhasspy_url,
                'rhasspy_tts_voice'        => array_key_exists('rhasspy_tts_voice', $data) ? $data['rhasspy_tts_voice'] : $preset->rhasspy_tts_voice,
                'rhasspy_incoming_enabled' => array_key_exists('rhasspy_incoming_enabled', $data) ? $data['rhasspy_incoming_enabled'] : $preset->rhasspy_incoming_enabled,
                'rhasspy_incoming_token'   => array_key_exists('rhasspy_incoming_token', $data) ? $data['rhasspy_incoming_token'] : $preset->rhasspy_incoming_token,
                'is_active' => $data['is_active'] ?? $preset->is_active,
                'is_default' => $data['is_default'] ?? $preset->is_default,
            ]);

            // Sync prompts if provided
            $this->syncPrompts($preset, $data);

            // If this is set as default, ensure only one default exists
            if (isset($data['is_default']) && $data['is_default']) {
                $this->setDefaultPreset($preset->id);
            }

            $this->presetRegistry->refresh();

            $this->logPresetUpdated($preset);

            return $preset->fresh();
        });
    }

    /**
     * @inheritDoc
     */
    public function updatePresetWithValidation(int $id, array $data): AiPreset
    {
        // Enhanced validation with engine config if provided
        if (isset($data['engine_name']) && isset($data['engine_config'])) {
            $this->validateEngineConfigData($data['engine_name'], $data['engine_config']);
        }

        $preset = $this->updatePreset($id, $data);

        return $preset;
    }

    /**
     * @inheritDoc
     */
    public function deletePreset(int $id): bool
    {
        $preset = $this->aiPresetModel->findOrFail($id);

        if ($preset->is_default) {
            throw new PresetException("Cannot delete default preset. Set another preset as default first.");
        }

        return $this->db->transaction(function () use ($preset) {
            $result = $preset->delete();
            $this->presetRegistry->refresh();

            $this->logPresetDeleted($preset->id);

            return $result;
        });
    }

    /**
     * @inheritDoc
     */
    public function deletePresetWithValidation(int $id): void
    {
        $preset = $this->findByIdOrFail($id);

        if ($preset->is_default) {
            throw new PresetException('Cannot delete default preset. Set another preset as default first.');
        }

        $this->deletePreset($id);
    }

    /**
     * @inheritDoc
     */
    public function findById(int $id): ?AiPreset
    {
        return $this->aiPresetModel->find($id);
    }

    /**
     * @inheritDoc
     */
    public function findByIdOrFail(int $id): AiPreset
    {
        return $this->aiPresetModel->findOrFail($id);
    }

    /**
     * @inheritDoc
     */
    public function findByCode(string $code): ?AiPreset
    {
        return $this->aiPresetModel
            ->whereRaw('LOWER(preset_code) = ?', [strtolower(trim($code))])
            ->first();
    }

    /**
     * @inheritDoc
     */
    public function getDefaultPreset(): ?AiPreset
    {
        return $this->aiPresetModel->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @inheritDoc
     */
    public function getDefaultOrFirstActivePreset(): ?AiPreset
    {
        // Try to get default preset first
        $defaultPreset = $this->getDefaultPreset();
        if ($defaultPreset) {
            return $defaultPreset;
        }

        // Fall back to first active preset
        return $this->aiPresetModel->where('is_active', true)
            ->orderBy('created_at', 'asc')
            ->first();
    }

    /**
     * @inheritDoc
     */
    public function duplicatePreset(int $id, ?string $newName = null): AiPreset
    {
        $originalPreset = $this->findByIdOrFail($id);

        $newName = $newName ?? ($originalPreset->name . ' (Copy)');

        // Ensure unique name
        $counter = 1;
        $baseName = $newName;
        while ($this->aiPresetModel->where('name', $newName)->exists()) {
            $newName = $baseName . ' (' . $counter . ')';
            $counter++;
        }

        return $this->db->transaction(function () use ($originalPreset, $newName) {

            $newPreset = $this->createPreset([
                // Identity
                'name'             => $newName,
                'description'      => $originalPreset->description,
                // Engine
                'engine_name'      => $originalPreset->engine_name,
                'engine_config'    => $originalPreset->engine_config,
                // Input
                'input_mode'          => $originalPreset->input_mode,
                'pool_relative_dates' => $originalPreset->pool_relative_dates,
                'pulse_dates'        => $originalPreset->pulse_dates,
                // Behaviour
                'agent_result_mode'  => $originalPreset->agent_result_mode,
                'max_context_limit'  => $originalPreset->max_context_limit,
                'max_context_limit_extended' => $originalPreset->max_context_limit_extended,
                'pre_pass_enabled'           => $originalPreset->pre_pass_enabled,
                'pre_pass_instruction'       => $originalPreset->pre_pass_instruction,
                'loop_interval'      => $originalPreset->loop_interval,
                'before_execution_wait' => $originalPreset->before_execution_wait,
                'error_behavior'     => $originalPreset->error_behavior,
                'allow_handoff_to'   => $originalPreset->allow_handoff_to,
                'allow_handoff_from' => $originalPreset->allow_handoff_from,
                // Plugins
                'plugins_disabled'   => $originalPreset->plugins_disabled,
                'cp_context_limit'   => $originalPreset->cp_context_limit,
                'pre_run_commands'   => $originalPreset->pre_run_commands,
                'turn_trigger'       => $originalPreset->turn_trigger,
                'preset_code_next'   => $originalPreset->preset_code_next,
                'voice_mp_commands'  => $originalPreset->voice_mp_commands,
                // Defrag
                'defrag_enabled'      => $originalPreset->defrag_enabled,
                'defrag_prompt'       => $originalPreset->defrag_prompt,
                'defrag_keep_per_day' => $originalPreset->defrag_keep_per_day,
                // Cycle prompt
                'cycle_prompt_preset_id' => $originalPreset->cycle_prompt_preset_id,
                // State — duplicates are inactive and never default
                'is_active'   => false,
                'is_default'  => false,
                // Spawn fields — duplicates are never spawns
                'is_spawned'       => false,
                'parent_preset_id' => null,
                'target_preset_id'         => null,
                'target_plugins_whitelist' => null,
            ]);

            $this->presetRegistry->refresh();
            $this->pluginManagerFactory->get()->initializeConfigsForPreset($newPreset);
            $this->logPresetDuplicated($originalPreset->id, $newPreset->id);

            return $newPreset;
        });

    }

    /**
     * @inheritDoc
     */
    public function testPreset(int $id): array
    {
        $preset = $this->findByIdOrFail($id);

        $testResult = $this->engineRegistry->testEngineConnection($preset->engine_name);

        return array_merge([
            'preset_id' => $id,
            'preset_name' => $preset->name,
            'engine_name' => $preset->engine_name
        ], $testResult);
    }

    /**
     * @inheritDoc
     */
    public function testPresetConfiguration(int $id): array
    {
        return $this->testPreset($id);
    }

    /**
     * @inheritDoc
     */
    public function testEngineConfiguration(string $engineName, array $config): array
    {
        if (!$this->engineRegistry->has($engineName)) {
            return [
                'success' => false,
                'error' => "Engine '$engineName' not found",
                'response_time' => null,
            ];
        }

        $startTime = microtime(true);

        try {
            $engine = $this->engineRegistry->get($engineName);
            $engineClass = get_class($engine);

            // First try to test configuration if method exists
            if (method_exists($engine, 'testConnection')) {
                // Create a temporary engine instance with the config for testing
                $tempEngine = new $engineClass(
                    $engine->http ?? app('Illuminate\Http\Client\Factory'),
                    $this->logger,
                    $this->cacheManager,
                    $config
                );
                $testResult = $tempEngine->testConnection();

                if ($testResult) {
                    $responseTime = round((microtime(true) - $startTime) * 1000);
                    return [
                        'success' => true,
                        'message' => 'Connection test successful',
                        'response_time' => $responseTime,
                    ];
                } else {
                    $responseTime = round((microtime(true) - $startTime) * 1000);
                    return [
                        'success' => false,
                        'error' => 'Connection test failed',
                        'response_time' => $responseTime,
                    ];
                }
            }

            // If no testConnection method, just validate config
            $validationErrors = $this->validateEngineConfig($engineName, $config);

            $responseTime = round((microtime(true) - $startTime) * 1000);

            if (empty($validationErrors)) {
                return [
                    'success' => true,
                    'message' => 'Configuration validation passed',
                    'response_time' => $responseTime,
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Configuration validation failed: ' . implode(', ', $validationErrors),
                    'response_time' => $responseTime,
                ];
            }

        } catch (\Exception $e) {
            $responseTime = round((microtime(true) - $startTime) * 1000);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'response_time' => $responseTime,
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function importRecommendedPreset(string $engineName, int $presetIndex): AiPreset
    {
        $recommendedPresets = $this->engineRegistry->getRecommendedPresets($engineName);

        if (!isset($recommendedPresets[$presetIndex])) {
            throw new PresetException('Recommended preset not found');
        }

        $recommendedPreset = $recommendedPresets[$presetIndex];

        $preset = $this->createPresetWithValidation([
            'name' => $recommendedPreset['name'],
            'description' => $recommendedPreset['description'],
            'engine_name' => $engineName,
            'engine_config' => $recommendedPreset['config'],
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->logRecommendedPresetImported($preset, $engineName, $recommendedPreset['name']);

        return $preset;
    }

    /**
     * @inheritDoc
     */
    public function getAllPresets(): Collection
    {
        return $this->aiPresetModel->orderBy('name')->get();
    }

    /**
     * @inheritDoc
     */
    public function getActivePresets(): Collection
    {
        return $this->presetRegistry->getActivePresets();
    }

    /**
     * @inheritDoc
     */
    public function getPresetsByEngine(string $engineName): Collection
    {
        return $this->aiPresetModel->where('engine_name', $engineName)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function getEngineDefaults(string $engineName): array
    {
        if (!$this->engineRegistry->has($engineName)) {
            throw new PresetException("Engine '$engineName' not found");
        }

        $engine = $this->engineRegistry->get($engineName);

        // Try instance method first, then static method for backward compatibility
        if (method_exists($engine, 'getDefaultConfig')) {
            return $engine->getDefaultConfig();
        }

        $engineClass = get_class($engine);
        if (method_exists($engineClass, 'getDefaultConfig')) {
            return $engineClass::getDefaultConfig();
        }

        return [];
    }

    /**
     * @inheritDoc
     */
    public function validateEngineConfig(string $engineName, array $config): array
    {
        if (!$this->engineRegistry->has($engineName)) {
            throw new PresetException("Engine '$engineName' not found");
        }

        $engine = $this->engineRegistry->get($engineName);

        // Try instance method first, then static method for backward compatibility
        if (method_exists($engine, 'validateConfig')) {
            return $engine->validateConfig($config);
        }

        $engineClass = get_class($engine);
        if (method_exists($engineClass, 'validateConfig')) {
            return $engineClass::validateConfig($config);
        }

        return []; // No validation errors if method doesn't exist
    }

    /**
     * @inheritDoc
     */
    public function validateEngineConfigData(string $engineName, array $config): void
    {
        $errors = $this->validateEngineConfig($engineName, $config);

        if (!empty($errors)) {
            throw new PresetException('Configuration validation failed: ' . implode(', ', $errors));
        }
    }

    /**
     * @inheritDoc
     */
    public function setDefaultPreset(int $id): bool
    {
        return $this->db->transaction(function () use ($id) {
            // Remove default from all presets
            $this->aiPresetModel->where('is_default', true)->update(['is_default' => false]);

            // Set new default
            $preset = $this->aiPresetModel->findOrFail($id);
            $preset->update(['is_default' => true, 'is_active' => true]);

            $this->presetRegistry->refresh();

            $this->logDefaultPresetSet($id);

            return true;
        });
    }

    /**
     * @inheritDoc
     */
    public function setDefaultPresetWithLogging(int $id): void
    {
        $this->setDefaultPreset($id);
    }

    /**
     * @inheritDoc
     */
    public function getPresetStatistics(): array
    {
        return [
            'total' => $this->aiPresetModel->count(),
            'active' => $this->aiPresetModel->where('is_active', true)->count(),
            'inactive' => $this->aiPresetModel->where('is_active', false)->count(),
            'by_engine' => $this->aiPresetModel->selectRaw('engine_name, COUNT(*) as count')
                ->groupBy('engine_name')
                ->pluck('count', 'engine_name')
                ->toArray(),
            'default_preset' => $this->getDefaultPreset()?->only(['id', 'name', 'engine_name']),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAvailableEngines(): array
    {
        return $this->engineRegistry->getAvailableEngines();
    }

    /**
     * @inheritDoc
     */
    public function searchPresets(string $query): Collection
    {
        return $this->aiPresetModel->where(function ($q) use ($query) {
            $q->where('name', 'like', "%{$query}%")
              ->orWhere('description', 'like', "%{$query}%");
        })
        ->where('is_active', true)
        ->orderBy('name')
        ->get();
    }

    /**
     * @inheritDoc
     */
    public function getHandoffTargets(AiPreset $excludePreset): Collection
    {
        return $this->aiPresetModel
            ->where('is_active', true)
            ->where('allow_handoff_to', true)
            ->where('id', '!=', $excludePreset->getId())
            ->orderBy('name')
            ->get();
    }

    // ============================================
    // Helper Methods
    // ============================================


    /**
     * Sync prompts for a preset from request data.
     *
     * Accepts:
     *   - $data['prompts']  array of {id?, code, content, description, is_active?}
     *   - legacy $data['system_prompt'] plain string (backward compat).
     *
     * Prompt writes are delegated to PresetPromptService so every content change is
     * versioned (PresetPromptVersion). Edits are attributed to the acting admin:
     * edited_by = 'human' + current user id.
     *
     * The prompt service snapshots ONLY when content actually changes, so re-saving
     * the preset form (which always sends every prompt) does not create spurious
     * versions for prompts the user didn't touch.
     */
    protected function syncPrompts(AiPreset $preset, array $data): void
    {
        $promptsData  = $data['prompts'] ?? null;
        $editorUserId = $this->authService->getCurrentUserId();

        // Legacy path: no prompts array but a system_prompt string was provided.
        if ($promptsData === null) {
            $legacyContent = $data['system_prompt'] ?? null;

            if ($preset->prompts()->count() === 0) {
                $this->promptService->create(
                    $preset,
                    ['code' => 'default', 'content' => $legacyContent ?? ''],
                    true, // setAsActive - first prompt
                    PresetPromptVersion::BY_HUMAN,
                    $editorUserId
                );
                $preset->refresh();
            }
            return;
        }

        // Delete prompts explicitly removed by the user (guard against emptying).
        $deletedIds = $data['deleted_prompt_ids'] ?? [];
        if (!empty($deletedIds)) {
            $remaining = $preset->prompts()->count() - count($deletedIds);
            if ($remaining >= 1) {
                foreach ($deletedIds as $deletedId) {
                    try {
                        $this->promptService->delete($preset, (int) $deletedId);
                    } catch (\RuntimeException $e) {
                        $this->logger->warning('syncPrompts: skipped prompt deletion', [
                            'preset_id' => $preset->id,
                            'prompt_id' => $deletedId,
                            'reason'    => $e->getMessage(),
                        ]);
                    }
                }
                $preset->refresh();
            }
        }

        if (empty($promptsData)) {
            return;
        }

        $activePromptId = $preset->active_prompt_id;
        $newActiveId    = null;

        foreach ($promptsData as $promptData) {
            if (!empty($promptData['id'])) {
                $existing = $this->promptService->findById($preset, (int) $promptData['id']);
                if (!$existing) {
                    continue; // id given but not in this preset - skip
                }
                $prompt = $this->promptService->update(
                    $preset,
                    (int) $promptData['id'],
                    [
                        'code'         => $promptData['code']        ?? $existing->code,
                        'content'      => $promptData['content']     ?? $existing->content,
                        'description'  => $promptData['description'] ?? $existing->description,
                        'edit_summary' => $promptData['edit_summary'] ?? null,
                    ],
                    PresetPromptVersion::BY_HUMAN,
                    $editorUserId
                );
            } else {
                $prompt = $this->promptService->create(
                    $preset,
                    [
                        'code'        => $promptData['code']        ?? 'default',
                        'content'     => $promptData['content']     ?? '',
                        'description' => $promptData['description'] ?? null,
                    ],
                    false,
                    PresetPromptVersion::BY_HUMAN,
                    $editorUserId
                );
            }

            if (!empty($promptData['is_active'])) {
                $newActiveId = $prompt->getId();
            }
        }

        // Ensure the preset always has at least one prompt.
        if ($preset->prompts()->count() === 0) {
            $prompt = $this->promptService->create(
                $preset,
                ['code' => 'default', 'content' => ''],
                true,
                PresetPromptVersion::BY_HUMAN,
                $editorUserId
            );
            $newActiveId = $prompt->getId();
        }

        // Resolve active prompt: explicit is_active -> previous active -> first.
        $preset->refresh();
        $resolvedActiveId = $newActiveId
            ?? $activePromptId
            ?? $preset->prompts()->orderBy('created_at')->value('id');

        if ($resolvedActiveId && $resolvedActiveId !== $preset->active_prompt_id) {
            $preset->active_prompt_id = $resolvedActiveId;
            $preset->saveQuietly();
        }
    }


    /**
     * Validate preset data
     *
     * @param array $data
     * @param integer|null $excludeId
     * @param boolean $skipSecretValidation
     * @return void
     */
    protected function validatePresetData(array $data, ?int $excludeId = null, bool $skipSecretValidation = false): void
    {
        $rules = [
            'target_preset_id'         => 'nullable|integer|exists:ai_presets,id',
            'target_plugins_whitelist' => 'nullable|string|max:500',
            'parent_preset_id' => 'nullable|integer|exists:ai_presets,id',
            'is_spawned'       => 'boolean',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'engine_name' => 'required|string|max:100',
            'input_mode' => ['required', 'in:single,pool'],
            'pool_relative_dates' => 'boolean',
            'pulse_dates' => 'boolean',
            'preset_code' => 'nullable|string|max:50',
            'plugins_disabled' => 'nullable|string|max:255',
            'engine_config' => 'array',
            'loop_interval' => 'nullable|integer|min:1|max:3600',
            'max_context_limit' => 'nullable|integer|min:0|max:100',
            'max_context_limit_extended' => 'nullable|integer|min:0|max:100',
            'pre_pass_enabled'           => 'boolean',
            'pre_pass_instruction'       => 'nullable|string|max:5000',
            'agent_result_mode' => 'nullable|in:tool_calls,internal',
            'preset_code_next' => 'nullable|string',
            'pre_run_commands' => 'nullable|string',
            'turn_trigger' => 'nullable|string|in:none,no_speak',
            'defrag_enabled'      => 'boolean',
            'defrag_prompt'       => 'nullable|string',
            'defrag_keep_per_day' => 'nullable|integer|min:1|max:20',
            'cycle_prompt_preset_id' => 'nullable|integer|exists:ai_presets,id',
            'cp_context_limit' => 'required|integer|min:4|max:20',
            'voice_mp_commands' => 'nullable|string',
            'default_call_message' => 'nullable|string',
            'before_execution_wait' => 'nullable|integer|min:1|max:60',
            'error_behavior' => 'nullable|in:stop,continue,fallback',
            'allow_handoff_to' => 'boolean',
            'allow_handoff_from' => 'boolean',
            'rhasspy_enabled'          => 'boolean',
            'rhasspy_url'              => 'nullable|string|url|max:255',
            'rhasspy_tts_voice'        => 'nullable|string|max:100',
            'rhasspy_incoming_enabled' => 'boolean',
            'rhasspy_incoming_token'   => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'created_by' => 'nullable|exists:users,id',
        ];

        // Add unique rule for name
        $nameRule = 'unique:ai_presets,name';
        if ($excludeId) {
            $nameRule .= ',' . $excludeId;
        }
        $rules['name'] .= '|' . $nameRule;

        $validator = $this->validator->make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Validate engine exists
        if (isset($data['engine_name']) && !$this->engineRegistry->has($data['engine_name'])) {
            throw new PresetException("Engine '{$data['engine_name']}' not found");
        }

        // Validate engine config if provided
        if (isset($data['engine_config']) && isset($data['engine_name'])) {
            $configErrors = $this->validateEngineConfig($data['engine_name'], $data['engine_config']);

            if ($skipSecretValidation) {
                $configErrors = $this->filterOutSecretErrors($data['engine_name'], $configErrors);
            }

            if (!empty($configErrors)) {
                throw new PresetException('Engine configuration validation failed: ' . implode(', ', $configErrors));
            }
        }

        if (isset($data['preset_code']) && !empty($data['preset_code'])) {
            $presetCodeRule = 'unique:ai_presets,preset_code';
            if ($excludeId) {
                $presetCodeRule .= ',' . $excludeId;
            }
            $rules['preset_code'] .= '|' . $presetCodeRule;
        }
    }

    /**
     * Log preset created
     *
     * @param AiPreset $preset
     * @return void
     */
    protected function logPresetCreated(AiPreset $preset): void
    {
        $this->logger->info('Preset created', [
            'preset_id' => $preset->id,
            'preset_name' => $preset->name,
            'engine_name' => $preset->engine_name,
            'created_by' => $this->authService->getCurrentUserId()
        ]);
    }

    /**
     * Log preset updated
     */
    protected function logPresetUpdated(AiPreset $preset): void
    {
        $this->logger->info('Preset updated', [
            'preset_id' => $preset->id,
            'preset_name' => $preset->name,
            'updated_by' => $this->authService->getCurrentUserId()
        ]);
    }

    /**
     * Log preset deleted
     *
     * @param integer $presetId
     * @return void
     */
    protected function logPresetDeleted(int $presetId): void
    {
        $this->logger->info('Preset deleted', [
            'preset_id' => $presetId,
            'deleted_by' => $this->authService->getCurrentUserId()
        ]);
    }

    /**
     * Log preset duplicated
     *
     * @param integer $originalId
     * @param integer $newId
     * @return void
     */
    protected function logPresetDuplicated(int $originalId, int $newId): void
    {
        $this->logger->info('Preset duplicated', [
            'original_preset_id' => $originalId,
            'new_preset_id' => $newId,
            'duplicated_by' => $this->authService->getCurrentUserId()
        ]);
    }

    /**
     * Log default preset set
     *
     * @param integer $presetId
     * @return void
     */
    protected function logDefaultPresetSet(int $presetId): void
    {
        $this->logger->info('Default preset set', [
            'preset_id' => $presetId,
            'set_by' => $this->authService->getCurrentUserId()
        ]);
    }

    /**
     * Log recommended preset imported
     *
     * @param AiPreset $preset
     * @param string $engineName
     * @param string $recommendedName
     * @return void
     */
    protected function logRecommendedPresetImported(AiPreset $preset, string $engineName, string $recommendedName): void
    {
        $this->logger->info('Recommended preset imported', [
            'preset_id' => $preset->id,
            'engine_name' => $engineName,
            'recommended_preset_name' => $recommendedName,
            'imported_by' => $this->authService->getCurrentUserId()
        ]);
    }

    /**
     * Remove engine-config validation errors that belong to secret (password)
     * fields, keeping every other error intact.
     *
     * Used on import, where secrets are intentionally null (stripped at export) and
     * must be entered by the user afterwards — so a "required api_key" error is not
     * a real problem, while an out-of-range temperature still is.
     *
     * Secret fields are discovered from the engine's own field declaration
     * (type: password) — the same source the exporter uses to strip them, so the
     * two sides stay symmetric with zero hardcoding.
     *
     * @param  array<string,string> $errors  Errors keyed by field name.
     * @return array<string,string>          Errors with secret-field entries removed.
     */
    protected function filterOutSecretErrors(string $engineName, array $errors): array
    {
        $secretFields = [];

        $fields = $this->engineRegistry->getEngineConfigFields($engineName);
        foreach ($fields as $key => $meta) {
            if (($meta['type'] ?? '') === 'password') {
                $secretFields[$key] = true;
            }
        }

        if (empty($secretFields)) {
            return $errors;
        }

        return array_filter(
            $errors,
            fn ($key) => !isset($secretFields[$key]),
            ARRAY_FILTER_USE_KEY
        );
    }


}

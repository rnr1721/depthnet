<?php

namespace App\Services\Agent;

use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\PluginManagerFactoryInterface;
use App\Contracts\Auth\AuthServiceInterface;
use App\Models\AiPreset;
use App\Exceptions\PresetException;
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
        protected PluginManagerFactoryInterface $pluginManagerFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function createPreset(array $data): AiPreset
    {
        $this->validatePresetData($data);

        return $this->db->transaction(function () use ($data) {
            $preset = $this->aiPresetModel->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'engine_name' => $data['engine_name'],
                'input_mode' => $data['input_mode'] ?? 'single',
                'preset_code' => $data['preset_code'] ?? null,
                'plugins_disabled' => $data['plugins_disabled'] ?? '',
                'engine_config' => $data['engine_config'] ?? [],
                'loop_interval' => $data['loop_interval'] ?? 15,
                'max_context_limit' => $data['max_context_limit'] ?? 8,
                'agent_result_mode' => $data['agent_result_mode'] ?? 'separate',
                'preset_code_next' => $data['preset_code_next'] ?? '',
                'pre_run_commands' => $data['pre_run_commands'] ?? '',
                'rag_preset_id' => $data['rag_preset_id'] ?? null,
                'rag_context_limit' => $data['rag_context_limit'] ?? null,
                'rag_results' => $data['rag_context_limit'] ?? null,
                'rag_mode' => $data['rag_mode'] ?? null,
                'rag_engine' => $data['rag_engine'] ?? null,
                'rag_relative_dates'         => $data['rag_relative_dates'] ?? false,
                'rag_journal_limit'          => $data['rag_journal_limit'] ?? 3,
                'rag_skills_limit'           => $data['rag_skills_limit'] ?? 3,
                'rag_content_limit'          => $data['rag_content_limit'] ?? 400,
                'rag_journal_context_window' => $data['rag_journal_context_window'] ?? 0,
                'defrag_enabled'      => $data['defrag_enabled'] ?? false,
                'defrag_prompt'       => $data['defrag_prompt'] ?? null,
                'defrag_keep_per_day' => $data['defrag_keep_per_day'] ?? 3,
                'voice_preset_id' => $data['voice_preset_id'] ?? null,
                'voice_context_limit' => $data['voice_context_limit'] ?? null,
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
                'name' => $data['name'] ?? $preset->name,
                'description' => $data['description'] ?? $preset->description,
                'engine_name' => $data['engine_name'] ?? $preset->engine_name,
                'input_mode' => array_key_exists('input_mode', $data) ? $data['input_mode'] : $preset->input_mode,
                'preset_code' => array_key_exists('preset_code', $data) ? $data['preset_code'] : $preset->preset_code,
                'plugins_disabled' => array_key_exists('plugins_disabled', $data) ? $data['plugins_disabled'] : $preset->plugins_disabled,
                'engine_config' => $data['engine_config'] ?? $preset->engine_config,
                'loop_interval' => $data['loop_interval'] ?? $preset->loop_interval,
                'max_context_limit' => $data['max_context_limit'] ?? $preset->max_context_limit,
                'agent_result_mode' => $data['agent_result_mode'] ?? $preset->agent_result_mode,
                'preset_code_next' => array_key_exists('preset_code_next', $data) ? $data['preset_code_next'] : $preset->preset_code_next,
                'pre_run_commands' => array_key_exists('pre_run_commands', $data) ? $data['pre_run_commands'] : $preset->pre_run_commands,
                'rag_preset_id' => array_key_exists('rag_preset_id', $data) ? $data['rag_preset_id'] : $preset->rag_preset_id,
                'rag_context_limit' => array_key_exists('rag_context_limit', $data) ? $data['rag_context_limit'] : $preset->rag_context_limit,
                'rag_results' => array_key_exists('rag_results', $data) ? $data['rag_results'] : $preset->rag_results,
                'rag_mode' => array_key_exists('rag_mode', $data) ? $data['rag_mode'] : $preset->rag_mode,
                'rag_engine' => array_key_exists('rag_engine', $data) ? $data['rag_engine'] : $preset->rag_engine,
                'rag_relative_dates'         => array_key_exists('rag_relative_dates', $data) ? $data['rag_relative_dates'] : $preset->rag_relative_dates,
                'rag_journal_limit'          => array_key_exists('rag_journal_limit', $data) ? $data['rag_journal_limit'] : $preset->rag_journal_limit,
                'rag_skills_limit'           => array_key_exists('rag_skills_limit', $data) ? $data['rag_skills_limit'] : $preset->rag_skills_limit,
                'rag_content_limit'          => array_key_exists('rag_content_limit', $data) ? $data['rag_content_limit'] : $preset->rag_content_limit,
                'rag_journal_context_window' => array_key_exists('rag_journal_context_window', $data) ? $data['rag_journal_context_window'] : $preset->rag_journal_context_window,
                'defrag_enabled'      => array_key_exists('defrag_enabled', $data) ? $data['defrag_enabled'] : $preset->defrag_enabled,
                'defrag_prompt'       => array_key_exists('defrag_prompt', $data) ? $data['defrag_prompt'] : $preset->defrag_prompt,
                'defrag_keep_per_day' => array_key_exists('defrag_keep_per_day', $data) ? $data['defrag_keep_per_day'] : $preset->defrag_keep_per_day,
                'voice_preset_id' => array_key_exists('voice_preset_id', $data) ? $data['voice_preset_id'] : $preset->voice_preset_id,
                'voice_context_limit' => array_key_exists('voice_context_limit', $data) ? $data['voice_context_limit'] : $preset->voice_context_limit,
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

        $newPreset = $this->createPresetWithValidation([
            'name' => $newName,
            'description' => $originalPreset->description,
            'engine_name' => $originalPreset->engine_name,
            'input_mode' => $originalPreset->input_mode,
            'plugins_disabled' => $originalPreset->plugins_disabled,
            'engine_config' => $originalPreset->engine_config,
            'is_active' => false, // New copies are inactive by default
            'is_default' => false, // Duplicated presets are never default
        ]);

        $this->presetRegistry->refresh();

        $this->pluginManagerFactory->get()->initializeConfigsForPreset($newPreset);

        $this->logPresetDuplicated($id, $newPreset->id);

        return $newPreset;
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

    // ============================================
    // Helper Methods
    // ============================================


    /**
     * Sync prompts for a preset from request data.
     *
     * Accepts two formats from the frontend:
     *   - $data['prompts']  — array of {id?, code, content, description, is_active?}
     *   - legacy $data['system_prompt'] — plain string (backward compat for importRecommendedPreset etc.)
     *
     * Rules:
     *   - Prompts present in the array are upserted (update if id exists, insert if not).
     *   - Prompts NOT in the array but belonging to the preset are left untouched
     *     (deletion is only via the dedicated PresetPromptController).
     *   - After sync, active_prompt_id is set to the prompt marked is_active,
     *     or falls back to the current active_prompt_id, or the first prompt.
     */
    protected function syncPrompts(AiPreset $preset, array $data): void
    {
        $promptsData = $data['prompts'] ?? null;

        // Legacy path: no prompts array but system_prompt string provided
        if ($promptsData === null) {
            $legacyContent = $data['system_prompt'] ?? null;

            // For a brand-new preset with no prompts yet, always create a default prompt
            if ($preset->prompts()->count() === 0) {
                $prompt = $preset->prompts()->create([
                    'code'    => 'default',
                    'content' => $legacyContent ?? '',
                ]);
                $preset->active_prompt_id = $prompt->id;
                $preset->saveQuietly();
            }
            return;
        }

        // Delete prompts explicitly removed by the user
        $deletedIds = $data['deleted_prompt_ids'] ?? [];
        if (!empty($deletedIds)) {
            // Safety: only delete prompts that belong to this preset and are not the last one
            $remaining = $preset->prompts()->count() - count($deletedIds);
            if ($remaining >= 1) {
                $preset->prompts()
                    ->whereIn('id', $deletedIds)
                    ->delete();

                // If active prompt was deleted, will be resolved below
                if (in_array($preset->active_prompt_id, $deletedIds)) {
                    $preset->active_prompt_id = null;
                }
            }
        }

        if (empty($promptsData)) {
            return;
        }

        $activePromptId = $preset->active_prompt_id;
        $newActiveId    = null;

        foreach ($promptsData as $promptData) {
            if (!empty($promptData['id'])) {
                // Update existing prompt that belongs to this preset
                $prompt = $preset->prompts()->find($promptData['id']);
                if ($prompt) {
                    $prompt->update([
                        'code'        => $promptData['code']        ?? $prompt->code,
                        'content'     => $promptData['content']     ?? $prompt->content,
                        'description' => $promptData['description'] ?? $prompt->description,
                    ]);
                }
            } else {
                // Create new prompt
                $prompt = $preset->prompts()->create([
                    'code'        => $promptData['code']        ?? 'default',
                    'content'     => $promptData['content']     ?? '',
                    'description' => $promptData['description'] ?? null,
                ]);
            }

            if (!empty($promptData['is_active'])) {
                $newActiveId = $prompt->id;
            }
        }

        // Ensure preset always has at least one prompt
        if ($preset->prompts()->count() === 0) {
            $prompt = $preset->prompts()->create(['code' => 'default', 'content' => '']);
            $newActiveId = $prompt->id;
        }

        // Update active_prompt_id if needed
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
     * @return void
     */
    protected function validatePresetData(array $data, ?int $excludeId = null): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'engine_name' => 'required|string|max:100',
            'input_mode' => ['required', 'in:single,pool'],
            'preset_code' => 'nullable|string|max:50',
            'plugins_disabled' => 'nullable|string|max:255',
            'engine_config' => 'array',
            'loop_interval' => 'nullable|integer|min:4|max:30',
            'max_context_limit' => 'nullable|integer|min:0|max:50',
            'agent_result_mode' => 'nullable|string',
            'preset_code_next' => 'nullable|string',
            'pre_run_commands' => 'nullable|string',
            'rag_preset_id' => 'nullable|integer|exists:ai_presets,id',
            'rag_context_limit' => 'required|integer|min:1|max:20',
            'rag_results' => 'required|integer|min:4|max:20',
            'rag_mode' => 'nullable|in:flat,associative',
            'rag_engine' => 'nullable|in:tfidf,embedding',
            'rag_relative_dates'         => 'boolean',
            'rag_journal_limit'          => 'nullable|integer|min:1|max:20',
            'rag_skills_limit'           => 'nullable|integer|min:1|max:20',
            'rag_content_limit'          => 'nullable|integer|min:100|max:2000',
            'rag_journal_context_window' => 'nullable|integer|min:0|max:5',
            'defrag_enabled'      => 'boolean',
            'defrag_prompt'       => 'nullable|string',
            'defrag_keep_per_day' => 'nullable|integer|min:1|max:20',
            'voice_preset_id' => 'nullable|integer|exists:ai_presets,id',
            'voice_context_limit' => 'required|integer|min:0|max:20',
            'cycle_prompt_preset_id' => 'nullable|integer|exists:ai_presets,id',
            'cp_context_limit' => 'required|integer|min:4|max:20',
            'voice_mp_commands' => 'nullable|string',
            'default_call_message' => 'nullable|string',
            'before_execution_wait' => 'nullable|integer|min:4|max:15',
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
}

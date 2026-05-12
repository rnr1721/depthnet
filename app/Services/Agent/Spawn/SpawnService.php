<?php

namespace App\Services\Agent\Spawn;

use App\Contracts\Agent\Cleanup\PresetCleanupFactoryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Spawn\SpawnServiceInterface;
use App\Exceptions\PresetException;
use App\Models\AiPreset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * SpawnService — creates and manages ephemeral child presets.
 *
 * Spawned presets are pure instruments: no identity, no memory, no personality.
 * They are owned by a parent preset and deleted automatically when the parent
 * is deleted (DB CASCADE). The agent can also kill them explicitly via SpawnPlugin.
 */
class SpawnService implements SpawnServiceInterface
{
    /**
     * Plugins that are always disabled on spawned presets.
     * Prevents personality/subjectivity plugins from loading on instruments.
     * Applied on top of any $overrides['plugins_disabled'] the caller provides.
     */
    private const SPAWN_BLACKLIST = [
        'being',
        'rhythm',
        'heart',
        'ontology',
        'spawn',  // no recursive spawning
    ];

    /**
     * Fields copied from the parent preset.
     * Intentionally excludes identity fields (preset_code, name, is_default,
     * is_spawned, parent_preset_id) which are set explicitly during spawn.
     */
    private const INHERITED_FIELDS = [
        'engine_name',
        'engine_config',
        'input_mode',
        'pool_relative_dates',
        'agent_result_mode',
        'max_context_limit',
        'loop_interval',
        'before_execution_wait',
        'error_behavior',
        'allow_handoff_to',
        'allow_handoff_from',
        'cp_context_limit',
        'pre_run_commands',
        'preset_code_next',
        'defrag_enabled',
        'defrag_prompt',
        'defrag_keep_per_day',
    ];

    public function __construct(
        protected PresetServiceInterface $presetService,
        protected PresetCleanupFactoryInterface $cleanupFactory,
        protected LoggerInterface $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // SpawnServiceInterface
    // -------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function spawn(
        int $parentPresetId,
        string $slug,
        string $systemPrompt,
        array $overrides = []
    ): AiPreset {
        $this->validateSlug($slug);

        $parent = $this->presetService->findByIdOrFail($parentPresetId);

        $presetCode = $this->buildPresetCode($parentPresetId, $slug);
        $name       = $parent->getName() . ' / ' . $slug;

        $this->ensureCodeAvailable($presetCode);

        $pluginsDisabled = $this->buildPluginsDisabled(
            $parent->getPluginsDisabled(),
            $overrides['plugins_disabled'] ?? ''
        );

        $data = $this->buildInheritedData($parent, $overrides);

        $data = array_merge($data, [
            'name'             => $name,
            'preset_code'      => $presetCode,
            'plugins_disabled' => $pluginsDisabled,
            'system_prompt'    => $systemPrompt,
            'is_active'        => true,
            'is_default'       => false,
            'is_spawned'       => true,
            'parent_preset_id' => $parentPresetId,
        ]);

        $spawned = DB::transaction(function () use ($data) {
            return $this->presetService->createPreset($data);
        });

        // Persist is_spawned and parent_preset_id — createPreset goes through
        // validation which doesn't know these fields yet; set them directly.
        $spawned->update([
            'is_spawned'       => true,
            'parent_preset_id' => $parentPresetId,
        ]);

        $this->logger->info('SpawnService: spawned preset created', [
            'parent_preset_id' => $parentPresetId,
            'spawned_preset_id' => $spawned->id,
            'preset_code'      => $presetCode,
        ]);

        return $spawned->fresh();
    }

    /**
     * @inheritDoc
     */
    public function kill(int $spawnedPresetId, int $parentPresetId): void
    {
        $spawned = $this->resolveOwned($spawnedPresetId, $parentPresetId);

        $this->presetService->deletePreset($spawned->id);

        $this->logger->info('SpawnService: spawned preset killed', [
            'parent_preset_id'  => $parentPresetId,
            'spawned_preset_id' => $spawnedPresetId,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function killAll(int $parentPresetId): int
    {
        $spawns = $this->listSpawns($parentPresetId);
        $count  = 0;

        foreach ($spawns as $spawn) {
            $this->presetService->deletePreset($spawn->id);
            $count++;
        }

        $this->logger->info('SpawnService: all spawned presets killed', [
            'parent_preset_id' => $parentPresetId,
            'count'            => $count,
        ]);

        return $count;
    }

    /**
     * @inheritDoc
     */
    public function reset(int $spawnedPresetId, int $parentPresetId, ?string $newPrompt = null): void
    {
        $spawned = $this->resolveOwned($spawnedPresetId, $parentPresetId);

        // Wipe all accumulated runtime data.
        $this->cleanupFactory->make()->clearPreset($spawned, [
            'clear_messages'      => true,
            'clear_memory'        => true,
            'clear_vector_memory' => true,
            'clear_journal'       => true,
            'clear_goals'         => true,
            'clear_workspace'     => true,
            'clear_person'        => true,
            // heart / ontology are blacklisted at spawn time, but clear them
            // defensively in case a future config change enables them.
            'clear_heart'         => true,
            'clear_ontology'      => true,
        ]);

        if ($newPrompt !== null) {
            $this->updatePrompt($spawnedPresetId, $parentPresetId, $newPrompt);
        }

        $this->logger->info('SpawnService: spawned preset reset', [
            'parent_preset_id'  => $parentPresetId,
            'spawned_preset_id' => $spawnedPresetId,
            'prompt_replaced'   => $newPrompt !== null,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function listSpawns(int $parentPresetId): Collection
    {
        return AiPreset::where('parent_preset_id', $parentPresetId)
            ->where('is_spawned', true)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function readPrompt(int $spawnedPresetId, int $parentPresetId): string
    {
        $spawned = $this->resolveOwned($spawnedPresetId, $parentPresetId);

        return $spawned->getSystemPrompt();
    }

    /**
     * @inheritDoc
     */
    public function updatePrompt(int $spawnedPresetId, int $parentPresetId, string $newPrompt): void
    {
        $spawned = $this->resolveOwned($spawnedPresetId, $parentPresetId);

        // Update the active prompt content directly.
        $activePrompt = $spawned->activePrompt;

        if (!$activePrompt) {
            // Safety: should always exist after createPreset, but handle gracefully.
            $spawned->prompts()->create([
                'code'    => 'default',
                'content' => $newPrompt,
            ]);
            $spawned->active_prompt_id = $spawned->prompts()->latest()->value('id');
            $spawned->saveQuietly();
            return;
        }

        $activePrompt->update(['content' => $newPrompt]);
    }

    /**
     * @inheritDoc
     */
    public function findSpawnByCode(string $presetCode, int $parentPresetId): ?AiPreset
    {
        return AiPreset::where('preset_code', $presetCode)
            ->where('parent_preset_id', $parentPresetId)
            ->where('is_spawned', true)
            ->first();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a spawned preset and verify it is owned by $parentPresetId.
     *
     * @throws PresetException
     */
    private function resolveOwned(int $spawnedPresetId, int $parentPresetId): AiPreset
    {
        $spawned = $this->presetService->findById($spawnedPresetId);

        if (!$spawned) {
            throw new PresetException("Spawned preset #{$spawnedPresetId} not found.");
        }

        if (!$spawned->is_spawned || (int) $spawned->parent_preset_id !== $parentPresetId) {
            throw new PresetException(
                "Preset #{$spawnedPresetId} is not owned by parent #{$parentPresetId}."
            );
        }

        return $spawned;
    }

    /**
     * Build preset_code: spawn_{parentId}_{slug}
     */
    private function buildPresetCode(int $parentPresetId, string $slug): string
    {
        return "spawn_{$parentPresetId}_{$slug}";
    }

    /**
     * Validate agent-supplied slug: lowercase letters, digits, underscores,
     * must start with a letter, max 40 chars.
     *
     * @throws \InvalidArgumentException
     */
    private function validateSlug(string $slug): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $slug)) {
            throw new \InvalidArgumentException(
                "Invalid spawn slug '{$slug}'. "
                . "Must start with a lowercase letter, contain only [a-z0-9_], max 40 chars."
            );
        }
    }

    /**
     * Throw if the generated preset_code is already taken.
     *
     * @throws PresetException
     */
    private function ensureCodeAvailable(string $presetCode): void
    {
        if ($this->presetService->findByCode($presetCode)) {
            throw new PresetException(
                "Spawn preset_code '{$presetCode}' already exists. Choose a different slug."
            );
        }
    }

    /**
     * Merge SPAWN_BLACKLIST with any disabled plugins from parent / overrides.
     */
    private function buildPluginsDisabled(string $parentDisabled, string $overrideDisabled): string
    {
        $parts = array_filter(array_merge(
            self::SPAWN_BLACKLIST,
            $parentDisabled  !== '' ? explode(',', $parentDisabled) : [],
            $overrideDisabled !== '' ? explode(',', $overrideDisabled) : [],
        ));

        return implode(',', array_unique(array_map('trim', $parts)));
    }

    /**
     * Build the data array inherited from the parent preset.
     * $overrides can replace any inherited field except identity fields.
     */
    private function buildInheritedData(AiPreset $parent, array $overrides): array
    {
        $data = [];

        foreach (self::INHERITED_FIELDS as $field) {
            $data[$field] = $overrides[$field] ?? $parent->{$field};
        }

        return $data;
    }
}

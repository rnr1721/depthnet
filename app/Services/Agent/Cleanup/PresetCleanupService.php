<?php

namespace App\Services\Agent\Cleanup;

use App\Contracts\Agent\Cleanup\PresetCleanupServiceInterface;
use App\Contracts\Agent\Goals\GoalServiceInterface;
use App\Contracts\Agent\Heart\HeartServiceInterface;
use App\Contracts\Agent\Journal\JournalServiceInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Memory\PersonMemoryServiceInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Ontology\OntologyServiceInterface;
use App\Contracts\Agent\Skills\SkillServiceInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryFactoryInterface;
use App\Contracts\Agent\Workspace\WorkspaceServiceInterface;
use App\Contracts\Chat\ChatServiceInterface;
use App\Models\Agent;
use App\Models\AiPreset;
use Psr\Log\LoggerInterface;

/**
 * PresetCleanupService — centralized cleanup for preset and agent data.
 *
 * Extracted from ChatController::clearHistory() so controllers
 * stay thin and cleanup logic is reusable across the application.
 */
class PresetCleanupService implements PresetCleanupServiceInterface
{
    public function __construct(
        protected ChatServiceInterface $chatService,
        protected MemoryServiceInterface $memoryService,
        protected VectorMemoryFactoryInterface $vectorMemoryFactory,
        protected WorkspaceServiceInterface $workspaceService,
        protected GoalServiceInterface $goalService,
        protected SkillServiceInterface $skillService,
        protected PersonMemoryServiceInterface $personMemoryService,
        protected JournalServiceInterface $journalService,
        protected OntologyServiceInterface $ontologyService,
        protected HeartServiceInterface $heartService,
        protected PresetServiceInterface $presetService,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function clearPreset(AiPreset $preset, array $options): array
    {
        $cleared = [];

        if ($this->option($options, 'clear_messages')) {
            $this->chatService->clearHistory($preset->getId());
            $cleared[] = 'messages';
        }

        if ($this->option($options, 'clear_memory')) {
            $this->memoryService->clearMemory($preset);
            $cleared[] = 'memory';
        }

        if ($this->option($options, 'clear_vector_memory')) {
            $vectorMemoryService = $this->vectorMemoryFactory->make();
            $vectorMemoryService->clearVectorMemories($preset);
            $cleared[] = 'vector_memory';
        }

        if ($this->option($options, 'clear_workspace')) {
            $this->workspaceService->clear($preset);
            $cleared[] = 'workspace';
        }

        if ($this->option($options, 'clear_goals')) {
            $this->goalService->clear($preset);
            $cleared[] = 'goals';
        }

        if ($this->option($options, 'clear_skills')) {
            $this->skillService->deleteAllSkills($preset);
            $cleared[] = 'skills';
        }

        if ($this->option($options, 'clear_person')) {
            $this->personMemoryService->clearAll($preset);
            $cleared[] = 'person';
        }

        if ($this->option($options, 'clear_journal')) {
            $this->journalService->clear($preset);
            $cleared[] = 'journal';
        }

        if ($this->option($options, 'clear_heart')) {
            $this->heartService->clear($preset);
            $cleared[] = 'heart';
        }

        if ($this->option($options, 'clear_ontology')) {
            $this->ontologyService->clear($preset);
            $cleared[] = 'ontology';
        }

        $this->logger->info('PresetCleanupService: cleared preset', [
            'preset_id'   => $preset->getId(),
            'preset_name' => $preset->getName(),
            'cleared'     => $cleared,
        ]);

        return $cleared;
    }

    /**
     * @inheritDoc
     */
    public function clearAgent(Agent $agent, array $options): array
    {
        $presetIds = $this->getAgentPresetIds($agent);
        $results   = [];

        foreach ($presetIds as $presetId) {
            $preset = $this->presetService->findById($presetId);
            if (!$preset) {
                continue;
            }

            $cleared = $this->clearPreset($preset, $options);

            if (!empty($cleared)) {
                $results[$preset->getName()] = $cleared;
            }
        }

        $this->logger->info('PresetCleanupService: cleared agent', [
            'agent_id'   => $agent->id,
            'agent_name' => $agent->name,
            'presets'    => array_keys($results),
        ]);

        return $results;
    }

    /**
     * @inheritDoc
     */
    public function getAgentPresetIds(Agent $agent): array
    {
        $agent->loadMissing('roles');

        $ids = [];

        // Planner
        $ids[] = $agent->planner_preset_id;

        // Role presets + validators
        foreach ($agent->roles as $role) {
            $ids[] = $role->preset_id;
            if ($role->validator_preset_id) {
                $ids[] = $role->validator_preset_id;
            }
        }

        // Deduplicate and filter nulls
        return array_values(array_unique(array_filter($ids)));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Read a boolean option from the options array.
     * Accepts both bool and string ('1', 'true', 'on', 'yes').
     */
    private function option(array $options, string $key, bool $default = false): bool
    {
        if (!isset($options[$key])) {
            return $default;
        }

        $value = $options[$key];

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

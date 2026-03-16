<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentInterface;
use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Jobs\ProcessAgentThinking;
use App\Models\AiPreset;
use Illuminate\Support\Facades\Artisan;
use Psr\Log\LoggerInterface;

class AgentJobService implements AgentJobServiceInterface
{
    /**
     * Queue name
     */
    private string $queue = 'ai';

    /**
     * Lock key prefix: task_lock_{presetId}
     */
    private const LOCK_PREFIX = 'task_lock_';

    public function __construct(
        private OptionsServiceInterface $options,
        private ChatStatusServiceInterface $chatStatusService,
        private PresetServiceInterface $presetService,
        private AgentInterface $agent,
        private LoggerInterface $logger
    ) {
    }

    // -------------------------------------------------------------------------
    // Interface implementation
    // -------------------------------------------------------------------------

    /** @inheritDoc */
    public function isActive(int $presetId): bool
    {
        return $this->chatStatusService->getPresetStatus($presetId);
    }

    /** @inheritDoc */
    public function canStart(int $presetId): bool
    {
        // Can start when active and not already running
        return $this->isActive($presetId) && !$this->isLocked($presetId);
    }

    /** @inheritDoc */
    public function canStop(int $presetId): bool
    {
        return $this->isLocked($presetId);
    }

    /** @inheritDoc */
    public function start(int $presetId, bool $singleMode = false): bool
    {

        // Single mode: run once only if not blocked
        if ($singleMode) {
            if ($this->isLocked($presetId)) {
                $this->logger->info("AgentJobService: Cannot start preset {$presetId} - already running");
                return false;
            }
            ProcessAgentThinking::dispatch($presetId)->onQueue($this->queue);
            return true;
        }

        // Cycle mode: must be active and not blocked
        if (!$this->canStart($presetId)) {
            $this->logger->info("AgentJobService: Cannot start preset {$presetId} - conditions not met");
            return false;
        }

        $this->logger->info("AgentJobService: Starting thinking loop for preset {$presetId}");
        ProcessAgentThinking::dispatch($presetId)->onQueue($this->queue);

        return true;
    }

    /** @inheritDoc */
    public function stop(int $presetId): bool
    {
        if (!$this->canStop($presetId)) {
            $this->logger->info("AgentJobService: Cannot stop preset {$presetId} - not running");
            return false;
        }

        $this->logger->info("AgentJobService: Stopping thinking loop for preset {$presetId}");
        $this->unlock($presetId);

        return true;
    }

    /** @inheritDoc */
    public function isLocked(int $presetId): bool
    {
        return $this->options->get(self::LOCK_PREFIX . $presetId, false) === true;
    }

    /** @inheritDoc */
    public function processThinkingCycle(int $presetId): void
    {
        if ($this->isLocked($presetId)) {
            $this->logger->info("AgentJobService: Skipped preset {$presetId} - active lock");
            return;
        }

        $this->executeThinkingCycle($presetId);
    }

    /** @inheritDoc */
    public function updateModelSettings(int $presetId, bool $isActive): bool
    {
        try {
            $wasActive = $this->isActive($presetId);

            $this->chatStatusService->setPresetStatus($presetId, $isActive);

            $this->logger->info("AgentJobService: Settings updated for preset {$presetId}", [
                'active'     => $isActive,
                'was_active' => $wasActive,
            ]);

            if ($isActive && !$wasActive) {
                // Fresh start — restart queue workers so the new job is picked up cleanly
                $this->restartQueue();
                $this->start($presetId);
            } elseif (!$isActive && $wasActive) {
                $this->stop($presetId);
            }

            return true;

        } catch (\Throwable $e) {
            $this->logger->error("AgentJobService: Failed to update settings for preset {$presetId}", [
                'error'  => $e->getMessage(),
                'active' => $isActive,
            ]);
            return false;
        }
    }

    /** @inheritDoc */
    public function getModelSettings(int $presetId): array
    {
        $isActive      = $this->isActive($presetId);
        $isLocked      = $this->isLocked($presetId);
        $canStart      = $this->canStart($presetId);

        // Auto-correct: active flag set but queue not running and not locked
        if ($isActive && !$isLocked && !$canStart) {
            $this->logger->info("AgentJobService: Auto-correcting state for preset {$presetId}");
            $this->chatStatusService->setPresetStatus($presetId, false);
            $isActive = false;
        }

        return [
            'preset_id'   => $presetId,
            'chat_active' => $isActive,
            'is_locked'   => $this->isLocked($presetId),
            'can_start'   => $this->canStart($presetId),
            'can_stop'    => $this->canStop($presetId),
        ];
    }

    /** @inheritDoc */
    public function getAllModelSettings(): array
    {
        $activePresetIds = $this->chatStatusService->getActivePresetIds();

        // Also include presets that have a lock (running but maybe not in active list)
        $result = [];
        foreach ($activePresetIds as $presetId) {
            $result[$presetId] = $this->getModelSettings($presetId);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Core thinking loop for one preset, with handoff support.
     *
     * @param integer $presetId
     * @return void
     */
    private function executeThinkingCycle(int $presetId): void
    {
        $this->lock($presetId);

        try {
            $this->logger->info("AgentJobService: Starting thinking cycle for preset {$presetId}");

            $mainPreset    = $this->presetService->findById($presetId);
            if (!$mainPreset) {
                $this->logger->error("AgentJobService: Preset {$presetId} not found — aborting cycle");
                return;
            }

            $currentPreset  = $mainPreset;
            $handoffChain   = [];
            $iterationCount = 0;
            $maxIterations  = 20;
            $handoffData    = null;

            while ($iterationCount < $maxIterations) {
                $iterationCount++;

                $this->logger->info("AgentJobService: Thinking iteration", [
                    'preset_id'      => $presetId,
                    'iteration'      => $iterationCount,
                    'current_preset' => $currentPreset->getName(),
                    'handoff_chain'  => $handoffChain,
                ]);

                if ($iterationCount > 1) {
                    sleep($currentPreset->getBeforeExecutionWait());
                }

                $agentResponse = $this->agent->think(
                    $currentPreset,
                    $mainPreset,
                    $handoffData['handoff_message'] ?? null
                );

                if ($agentResponse->hasError()) {
                    $this->logger->error("AgentJobService: Agent error", [
                        'preset_id' => $presetId,
                        'error'     => $agentResponse->getErrorMessage(),
                        'preset'    => $currentPreset->getName(),
                    ]);
                    break;
                }

                $message = $agentResponse->getMessage();
                $this->logger->info("AgentJobService: Iteration completed", [
                    'preset_id'  => $presetId,
                    'message_id' => $message->id,
                    'preset'     => $currentPreset->getName(),
                ]);

                if (!$agentResponse->hasHandoff()) {
                    $this->logger->info("AgentJobService: No handoff, cycle complete for preset {$presetId}");
                    break;
                }

                $handoffData      = $agentResponse->getHandoffData();
                $targetPresetCode = $handoffData['target_preset'] ?? null;

                if (!$targetPresetCode) {
                    $this->logger->warning("AgentJobService: Empty handoff target for preset {$presetId}");
                    break;
                }

                $targetPreset = $this->presetService->findByCode($targetPresetCode);
                if (!$targetPreset) {
                    $this->logger->error("AgentJobService: Handoff target not found", [
                        'preset_id'   => $presetId,
                        'target_code' => $targetPresetCode,
                    ]);
                    break;
                }

                if (!$currentPreset->allowsHandoffFrom()) {
                    $this->logger->warning("AgentJobService: Preset does not allow handoff from", [
                        'preset_id'      => $presetId,
                        'current_preset' => $currentPreset->getName(),
                    ]);
                    break;
                }

                if (!$targetPreset->allowsHandoffTo()) {
                    $this->logger->warning("AgentJobService: Target preset does not allow handoff to", [
                        'preset_id'     => $presetId,
                        'target_preset' => $targetPreset->getName(),
                    ]);
                    break;
                }

                if (in_array($targetPresetCode, $handoffChain, true)) {
                    $this->logger->warning("AgentJobService: Handoff cycle detected", [
                        'preset_id'   => $presetId,
                        'target_code' => $targetPresetCode,
                        'chain'       => $handoffChain,
                    ]);
                    break;
                }

                $currentPreset   = $targetPreset;
                $handoffChain[]  = $targetPresetCode;

                $this->logger->info("AgentJobService: Handoff successful", [
                    'preset_id' => $presetId,
                    'from'      => $handoffChain[count($handoffChain) - 2] ?? $mainPreset->getPresetCode(),
                    'to'        => $targetPresetCode,
                    'chain_len' => count($handoffChain),
                ]);
            }

            if ($iterationCount >= $maxIterations) {
                $this->logger->warning("AgentJobService: Max iterations reached for preset {$presetId}", [
                    'max_iterations' => $maxIterations,
                    'handoff_chain'  => $handoffChain,
                ]);
            }

            $this->logger->info("AgentJobService: Cycle completed for preset {$presetId}", [
                'total_iterations' => $iterationCount,
                'handoff_chain'    => $handoffChain,
            ]);

            $this->scheduleNextCycle($presetId, $mainPreset);

        } catch (\Throwable $e) {
            $this->logger->error("AgentJobService: Cycle failed for preset {$presetId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            $this->unlock($presetId);
        }
    }

    /**
     * Schedule the next cycle for this preset using its own loop_interval.
     *
     * @param integer $presetId
     * @param AiPreset $preset
     * @return void
     */
    private function scheduleNextCycle(int $presetId, AiPreset $preset): void
    {
        if (!$this->chatStatusService->getPresetStatus($presetId)) {
            $this->logger->info("AgentJobService: Not scheduling next cycle — preset {$presetId} inactive");
            return;
        }

        $delay = $preset->getLoopInterval();
        ProcessAgentThinking::dispatch($presetId)->onQueue($this->queue)->delay($delay);

        $this->logger->info("AgentJobService: Next cycle scheduled for preset {$presetId}", [
            'delay_seconds' => $delay,
        ]);
    }

    /**
     * Lock preset
     *
     * @param integer $presetId
     * @return void
     */
    private function lock(int $presetId): void
    {
        $this->options->set(self::LOCK_PREFIX . $presetId, true);
    }

    /**
     * Unlock preset
     *
     * @param integer $presetId
     * @return void
     */
    private function unlock(int $presetId): void
    {
        $this->options->set(self::LOCK_PREFIX . $presetId, false);
    }

    /**
     * Restart Laravel queue workers (applies when new jobs need fresh state).
     *
     * @return void
     */
    private function restartQueue(): void
    {
        try {
            Artisan::call('queue:restart');
            $this->logger->info("AgentJobService: Queue restarted");
        } catch (\Throwable $e) {
            $this->logger->error("AgentJobService: Failed to restart queue", [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

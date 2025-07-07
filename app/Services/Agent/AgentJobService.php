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
     *
     * @var string
     */
    private string $queue = 'ai';

    /**
     * Lock ket in database options
     */
    private const LOCK_KEY = 'task_lock';

    public function __construct(
        private OptionsServiceInterface $options,
        private ChatStatusServiceInterface $chatStatusService,
        private PresetServiceInterface $presetService,
        private AgentInterface $agent,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
    */
    public function isActive(): bool
    {
        return $this->chatStatusService->getChatStatus();
    }

    /**
     * @inheritDoc
    */
    public function canStart(): bool
    {
        if (!$this->isActive()) {
            return true;
        }
        return $this->isActive() && !$this->isLocked();
    }

    /**
     * @inheritDoc
    */
    public function canStop(): bool
    {
        return $this->isLocked();
    }

    /**
     * @inheritDoc
    */
    public function start(): bool
    {
        if (!$this->canStart()) {
            $this->logger->info("AgentJobService: Cannot start - conditions not met");
            return false;
        }

        $this->logger->info("AgentJobService: Starting agent thinking process");
        ProcessAgentThinking::dispatch()->onQueue($this->queue);

        return true;
    }

    /**
     * @inheritDoc
    */
    public function stop(): bool
    {
        if (!$this->canStop()) {
            $this->logger->info("AgentJobService: Cannot stop - not running");
            return false;
        }

        $this->logger->info("AgentJobService: Stopping agent thinking process");
        $this->unlock();

        return true;
    }

    /**
     * @inheritDoc
    */
    public function isLocked(): bool
    {
        return $this->options->get(self::LOCK_KEY, false) === true;
    }

    /**
     * @inheritDoc
    */
    public function processThinkingCycle(): void
    {

        if ($this->isLocked()) {
            $this->logger->info("AgentJobService: Skipped due to active lock");
            return;
        }

        $this->executeThinkingCycle();
    }

    /**
     * @inheritDoc
    */
    public function updateModelSettings(int $presetId, bool $isActive): bool
    {
        try {
            $wasActive = $this->isActive();

            $this->presetService->setDefaultPreset($presetId);
            $this->chatStatusService->setChatStatus($isActive);

            $this->logger->info("AgentJobService: Model settings updated", [
                'preset_id' => $presetId,
                'active' => $isActive,
                'was_active' => $wasActive
            ]);

            $this->restartQueue();

            if ($isActive && !$wasActive) {
                $this->start();
            } elseif (!$isActive && $wasActive) {
                $this->stop();
            }

            return true;

        } catch (\Throwable $e) {
            $this->logger->error("AgentJobService: Failed to update model settings", [
                'error' => $e->getMessage(),
                'model' => $presetId,
                'active' => $isActive
            ]);

            return false;
        }
    }

    /**
     * @inheritDoc
    */
    public function getModelSettings(): array
    {
        $isActive = $this->isActive();
        $canActuallyStart = $this->canStart();

        // If model is marked active but cannot actually start due to queue issues
        if ($isActive && !$canActuallyStart && !$this->isLocked()) {
            $this->logger->info("AgentJobService: Auto-correcting model state in getModelSettings()");
            $this->chatStatusService->setChatStatus(false);
            $isActive = false;
        }

        return [
            'preset_id' => $this->presetService->getDefaultPreset()->getId(),
            'chat_active' => $isActive,
            'is_locked' => $this->isLocked(),
            'can_start' => $this->canStart(),
            'can_stop' => $this->canStop()
        ];
    }

    /**
     * Execute thinking cycle with handoff support
     *
     * @return void
     */
    private function executeThinkingCycle(): void
    {
        $this->lock();

        try {
            $this->logger->info("AgentJobService: Starting thinking cycle");

            $mainPreset = $this->presetService->getDefaultPreset();
            $currentPreset = $mainPreset;
            $handoffChain = [];
            $iterationCount = 0;
            $maxIterations = 20;

            while ($iterationCount < $maxIterations) {
                $iterationCount++;

                $this->logger->info("AgentJobService: Thinking iteration", [
                    'iteration' => $iterationCount,
                    'current_preset' => $currentPreset->getName(),
                    'main_preset' => $mainPreset->getName(),
                    'handoff_chain' => $handoffChain
                ]);

                if ($iterationCount > 1) {
                    $waitTime = $currentPreset->getBeforeExecutionWait();

                    sleep($waitTime);
                }

                $agentResponse = $this->agent->think(
                    $currentPreset,
                    $mainPreset,
                    $handoffData['handoff_message'] ?? null
                );

                if ($agentResponse->hasError()) {
                    $this->logger->error("AgentJobService: Agent error", [
                        'error' => $agentResponse->getErrorMessage(),
                        'preset' => $currentPreset->getName()
                    ]);
                    break;
                }

                $message = $agentResponse->getMessage();
                $this->logger->info("AgentJobService: Iteration completed", [
                    'message_id' => $message->id,
                    'preset' => $currentPreset->getName()
                ]);

                if (!$agentResponse->hasHandoff()) {
                    $this->logger->info("AgentJobService: No handoff, cycle complete");
                    break;
                }

                $handoffData = $agentResponse->getHandoffData();
                $targetPresetCode = $handoffData['target_preset'] ?? null;

                if (!$targetPresetCode) {
                    $this->logger->warning("AgentJobService: Empty handoff target");
                    break;
                }

                $targetPreset = $this->findPresetByCode($targetPresetCode);
                if (!$targetPreset) {
                    $this->logger->error("AgentJobService: Target preset not found", [
                        'target_code' => $targetPresetCode
                    ]);
                    break;
                }

                if (!$currentPreset->allowsHandoffFrom()) {
                    $this->logger->warning("AgentJobService: Current preset does not allow handoff from", [
                        'current_preset' => $currentPreset->getName()
                    ]);
                    break;
                }

                if (!$targetPreset->allowsHandoffTo()) {
                    $this->logger->warning("AgentJobService: Target preset does not allow handoff to", [
                        'target_preset' => $targetPreset->getName()
                    ]);
                    break;
                }

                if (in_array($targetPresetCode, $handoffChain)) {
                    $this->logger->warning("AgentJobService: Handoff cycle detected", [
                        'target_code' => $targetPresetCode,
                        'chain' => $handoffChain
                    ]);
                    break;
                }

                $currentPreset = $targetPreset;
                $handoffChain[] = $targetPresetCode;

                $this->logger->info("AgentJobService: Handoff successful", [
                    'from' => $handoffChain[count($handoffChain) - 2] ?? $mainPreset->getPresetCode(),
                    'to' => $targetPresetCode,
                    'chain_length' => count($handoffChain)
                ]);
            }

            if ($iterationCount >= $maxIterations) {
                $this->logger->warning("AgentJobService: Max iterations reached", [
                    'max_iterations' => $maxIterations,
                    'handoff_chain' => $handoffChain
                ]);
            }

            $this->logger->info("AgentJobService: Thinking cycle completed", [
                'total_iterations' => $iterationCount,
                'handoff_chain' => $handoffChain
            ]);

            $this->scheduleNextCycle();

        } catch (\Throwable $e) {
            $this->logger->error("AgentJobService: Failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        } finally {
            $this->unlock();
        }
    }

    /**
     * Find preset by code
     */
    private function findPresetByCode(string $code): ?AiPreset
    {
        return $this->presetService->findByCode($code);
    }

    /**
     * Schedule next cycle
     *
     * @return void
     */
    private function scheduleNextCycle(): void
    {
        if (!$this->chatStatusService->getChatStatus()) {
            $this->logger->info("AgentJobService: Not scheduling next cycle - chat inactive");
            return;
        }
        $defaultPreset = $this->presetService->getDefaultPreset();
        $thinkingDelay = $defaultPreset->getLoopInterval();
        ProcessAgentThinking::dispatch()->onQueue($this->queue)->delay($thinkingDelay);
    }

    /**
     * Lock the ability to think, if think process running
     *
     * @return void
     */
    private function lock(): void
    {
        $this->options->set(self::LOCK_KEY, true);
    }

    /**
     * Unlock after finish thinking operation
     *
     * @return void
     */
    private function unlock(): void
    {
        $this->options->set(self::LOCK_KEY, false);
    }

    /**
     * Restart Laravel Queue as in artisan queue:restart
     *
     * @return void
     */
    private function restartQueue(): void
    {
        try {
            Artisan::call('queue:restart');
            $this->logger->info("AgentJobService: Queue restarted successfully");
        } catch (\Throwable $e) {
            $this->logger->error("AgentJobService: Failed to restart queue", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

}

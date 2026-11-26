<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentInterface;
use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Jobs\ProcessAgentThinking;
use App\Models\AiPreset;
use App\Services\Agent\Status\TracksAgentStatus;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Artisan;
use Psr\Log\LoggerInterface;

class AgentJobService implements AgentJobServiceInterface
{
    use TracksAgentStatus;

    private string $queue = 'ai';
    private const LOCK_PREFIX = 'task_lock_';

    /**
     * Lock TTL in seconds — safety net if process dies without releasing.
     *
     * Was a fixed 300s. A cycle with a reasoner model can exceed that:
     * pre-pass (up to the 120s HTTP timeout) + 3s cooldown + main generation
     * (up to 120s) + inner voices + RAG query formulation + slow tools.
     * When the lock expires mid-cycle, canStart() lets a SECOND cycle of the
     * same preset run in parallel. Configurable: config('ai.cycle_lock_ttl').
     */
    private function lockTtl(): int
    {
        return max(60, (int) config('ai.cycle_lock_ttl', 900));
    }

    public function __construct(
        private OptionsServiceInterface $options,
        private ChatStatusServiceInterface $chatStatusService,
        private PresetServiceInterface $presetService,
        private AgentInterface $agent,
        private Cache $cache,
        private LoggerInterface $logger
    ) {
    }

    /** @inheritDoc */
    public function isActive(int $presetId): bool
    {
        return $this->chatStatusService->getPresetStatus($presetId);
    }

    /** @inheritDoc */
    public function canStart(int $presetId): bool
    {
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
        if ($singleMode) {
            if ($this->isLocked($presetId)) {
                $this->logger->info("AgentJobService: Cannot start preset {$presetId} - already running");
                return false;
            }
            ProcessAgentThinking::dispatch($presetId)->onQueue($this->queue);
            $this->agentStatus()?->queued($presetId);
            return true;
        }

        if (!$this->canStart($presetId)) {
            $this->logger->info("AgentJobService: Cannot start preset {$presetId} - conditions not met");
            return false;
        }

        $this->logger->info("AgentJobService: Starting thinking loop for preset {$presetId}");
        ProcessAgentThinking::dispatch($presetId)->onQueue($this->queue);
        $this->agentStatus()?->queued($presetId);

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
        // The running cycle is not interrupted — it finishes on its own.
        // Tell the UI so "stop" doesn't look ignored while "thinking" stays on.
        $this->agentStatus()?->markStopping($presetId);
        $this->releaseLock($presetId);

        return true;
    }

    /** @inheritDoc */
    public function isLocked(int $presetId): bool
    {
        return $this->cache->has(self::LOCK_PREFIX . $presetId);
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
        $isActive = $this->isActive($presetId);
        $isLocked = $this->isLocked($presetId);
        $canStart = $this->canStart($presetId);

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
     * Execute a single thinking cycle for one preset.
     *
     * Uses atomic cache lock — if another process is already running
     * this preset, the lock acquisition fails and we bail out.
     * TTL ensures the lock auto-expires if the process crashes.
     */
    private function executeThinkingCycle(int $presetId): void
    {
        $lock = $this->cache->lock(self::LOCK_PREFIX . $presetId, $this->lockTtl());

        if (!$lock->get()) {
            $this->logger->info("AgentJobService: Skipped preset {$presetId} — could not acquire lock");
            return;
        }

        $status  = $this->agentStatus();
        $outcome = 'waiting';
        $extra   = [];
        $status?->beginCycle($presetId);

        try {
            $preset = $this->presetService->findById($presetId);
            if (!$preset) {
                $this->logger->error("AgentJobService: Preset {$presetId} not found — aborting cycle");
                $outcome = 'error';
                $extra   = ['error' => 'Preset not found'];
                return;
            }

            $this->logger->info("AgentJobService: Starting thinking cycle", [
                'preset_id' => $presetId,
                'preset'    => $preset->getName(),
            ]);

            $agentResponse = $this->agent->think($preset);

            if ($agentResponse->hasError()) {
                $this->logger->error("AgentJobService: Agent error", [
                    'preset_id' => $presetId,
                    'error'     => $agentResponse->getErrorMessage(),
                ]);
                $outcome = 'error';
                $extra   = ['error' => (string) $agentResponse->getErrorMessage()];
                return;
            }

            $this->logger->info("AgentJobService: Cycle completed", [
                'preset_id'  => $presetId,
                'message_id' => $agentResponse->getMessage()->id,
            ]);

            $delay = $this->scheduleNextCycle($presetId, $preset);
            if ($delay !== null) {
                $outcome = 'sleeping';
                $extra   = ['next_at' => time() + $delay];
            }

        } catch (\Throwable $e) {
            $this->logger->error("AgentJobService: Cycle failed for preset {$presetId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $outcome = 'error';
            $extra   = ['error' => $e->getMessage()];
            throw $e;
        } finally {
            $status?->endCycle($outcome, $extra);
            $lock->release();
        }
    }

    /**
     * Schedule the next cycle — only if preset is still active.
     *
     * @return int|null delay in seconds, or null when nothing was scheduled
     */
    private function scheduleNextCycle(int $presetId, AiPreset $preset): ?int
    {
        if (!$this->chatStatusService->getPresetStatus($presetId)) {
            $this->logger->info("AgentJobService: Not scheduling next cycle — preset {$presetId} inactive");
            return null;
        }

        $delay = (int) $preset->getLoopInterval();
        ProcessAgentThinking::dispatch($presetId)->onQueue($this->queue)->delay($delay);

        $this->logger->info("AgentJobService: Next cycle scheduled for preset {$presetId}", [
            'delay_seconds' => $delay,
        ]);

        return $delay;
    }

    /**
     * Force-release a lock (used by stop()).
     */
    private function releaseLock(int $presetId): void
    {
        $this->cache->lock(self::LOCK_PREFIX . $presetId)->forceRelease();
    }

    /**
     * Restart Laravel queue workers.
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

<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentInterface;
use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Jobs\ProcessAgentThinking;
use Psr\Log\LoggerInterface;

class AgentJobService implements AgentJobServiceInterface
{
    private const LOCK_KEY = 'task_lock';
    private const MODEL_DEFAULT_KEY = 'model_default';
    private const MODEL_ACTIVE_KEY = 'model_active';
    private const MODE_KEY = 'model_agent_mode';
    private const TIMEOUT_KEY = 'model_timeout_between_requests';

    public function __construct(
        private OptionsServiceInterface $options,
        private AgentInterface $agent,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
    */
    public function isActive(): bool
    {
        return $this->options->get(self::MODEL_ACTIVE_KEY, false);
    }

    /**
     * @inheritDoc
    */
    public function canStart(): bool
    {
        $mode = $this->options->get(self::MODE_KEY, 'looped');
        return $this->isActive() && $mode === 'looped' && !$this->isLocked();
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
        ProcessAgentThinking::dispatch();

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
        $mode = $this->options->get(self::MODE_KEY, 'looped');

        if (!$this->isActive() || $mode === 'single') {
            $this->unlock();
            return;
        }

        if ($this->isLocked()) {
            $this->logger->info("AgentJobService: Skipped due to active lock");
            return;
        }

        $this->executeThinkingCycle();
    }

    /**
     * @inheritDoc
    */
    public function updateModelSettings(string $modelName, bool $isActive): bool
    {
        try {
            $wasActive = $this->isActive();

            $this->options->set(self::MODEL_DEFAULT_KEY, $modelName);
            $this->options->set(self::MODEL_ACTIVE_KEY, $isActive);

            $this->logger->info("AgentJobService: Model settings updated", [
                'model' => $modelName,
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
                'model' => $modelName,
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
            $this->options->set(self::MODEL_ACTIVE_KEY, false);
            $isActive = false;
        }

        return [
            'model_default' => $this->options->get(self::MODEL_DEFAULT_KEY, 'default'),
            'model_active' => $isActive,
            'model_agent_mode' => $this->options->get(self::MODE_KEY, 'looped'),
            'is_locked' => $this->isLocked(),
            'can_start' => $this->canStart(),
            'can_stop' => $this->canStop()
        ];
    }

    private function executeThinkingCycle(): void
    {
        $this->lock();

        try {
            $this->logger->info("AgentJobService: Starting thinking cycle");

            $message = $this->agent->think();

            $this->logger->info("AgentJobService: Thinking completed", [
                'message_id' => $message->id
            ]);

            $this->scheduleNextCycle();

        } catch (\Throwable $e) {
            $this->logger->error("AgentJobService: Failed", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        } finally {
            $this->unlock();
        }
    }

    private function scheduleNextCycle(): void
    {
        $thinkingDelay = $this->options->get(self::TIMEOUT_KEY, 15);
        ProcessAgentThinking::dispatch()->delay($thinkingDelay);
    }

    private function lock(): void
    {
        $this->options->set(self::LOCK_KEY, true);
    }

    private function unlock(): void
    {
        $this->options->set(self::LOCK_KEY, false);
    }

    private function restartQueue(): void
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('queue:restart');
            $this->logger->info("AgentJobService: Queue restarted successfully");
        } catch (\Throwable $e) {
            $this->logger->error("AgentJobService: Failed to restart queue", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

}

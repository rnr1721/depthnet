<?php

namespace App\Console\Commands;

use App\Contracts\Agent\AgentJobServiceFactoryInterface;
use App\Contracts\Agent\Wake\WakeScheduleServiceInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Models\Message;
use App\Models\WakeSchedule;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;

/**
 * Fires due wake schedules. Scheduled to run every minute (see Kernel).
 *
 * Delivery mirrors AgentMessageService::deliver() exactly — a wake is just a
 * time-triggered message the agent addressed to its future self:
 *
 *   1. Write a user-role message carrying the agent's wake_message, marked
 *      metadata['source'] = wake, so the woken cycle can tell "I scheduled this"
 *      apart from "a human spoke" (parallels cycleWasOrchestrated's source check).
 *      If the preset is in pool mode, add to the pool instead.
 *   2. Kick the thinking loop via AgentJobService::start(), using singleMode when
 *      the agent isn't already looping — same branch as deliver().
 *   3. ONLY THEN advance the schedule (recomputeAfterFire). Ordering matters:
 *      recompute-after-enqueue means a crash re-fires (at-least-once) rather than
 *      silently dropping a wake.
 *
 * Per-row try/catch: one malformed schedule must never block the rest of the
 * calendar.
 */
class WakeDispatchCommand extends Command
{
    protected $signature = 'wake:dispatch';
    protected $description = 'Fire any wake schedules that are due.';

    public function __construct(
        protected WakeScheduleServiceInterface $wakeService,
        protected AgentJobServiceFactoryInterface $agentJobFactory,
        protected ChatStatusServiceInterface $chatStatusService,
        protected InputPoolServiceInterface $inputPoolService,
        protected Message $messageModel,
        protected LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $due = $this->wakeService->due();

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        $this->logger->info('WakeDispatch: firing due schedules', ['count' => $due->count()]);

        foreach ($due as $schedule) {
            try {
                $this->fire($schedule);
            } catch (\Throwable $e) {
                $this->logger->error('WakeDispatch: schedule failed to fire', [
                    'schedule_id' => $schedule->getId(),
                    'preset_id'   => $schedule->preset_id,
                    'error'       => $e->getMessage(),
                ]);
                // Do NOT recompute — leaving next_run_at in the past means the row
                // is retried next tick rather than lost. For a `once` that keeps
                // failing this could loop; a max-attempts guard is a later refinement.
            }
        }

        return self::SUCCESS;
    }

    protected function fire(WakeSchedule $schedule): void
    {
        $preset = $schedule->preset; // eager BelongsTo
        if (!$preset) {
            // Preset gone (shouldn't happen — cascade delete) — retire the row.
            $schedule->update(['enabled' => false, 'next_run_at' => null]);
            return;
        }

        $presetId = $preset->getId();
        $message  = (string) $schedule->wake_message;

        // 1. Materialise the wake as an initiating user message BEFORE waking the
        //    loop. This mirrors ChatService::sendVoiceInput() — the canonical
        //    "external trigger in pool mode" path — NOT AgentMessageService::deliver
        //    (which is inter-agent and materialises differently).
        //
        //    Why the difference matters: if we only add to the pool and leave, the
        //    pool is flushed later, inside handleResponse's tail, AFTER the model's
        //    reply is already persisted. The wake message then gets a HIGHER id than
        //    the reply and displays out of order (and with a null source). Flushing
        //    the pool and writing the message here — before start() — gives it a
        //    lower id than the reply and the correct source, exactly like voice input.
        if ($this->inputPoolService->isEnabled($preset)) {
            $this->inputPoolService->add($presetId, WakeSchedule::SOURCE_WAKE, $message);
            $content = $this->inputPoolService->getAllAsJSON($preset) ?? $message;
            $this->inputPoolService->clear($presetId, true);
        } else {
            $content = $message;
        }

        $this->messageModel->create([
            'role'               => 'user',
            'content'            => $content,
            'from_user_id'       => null,
            'preset_id'          => $presetId,
            'is_visible_to_user' => true,
            'metadata'           => ['source' => WakeSchedule::SOURCE_WAKE],
        ]);

        // 2. Kick the loop — singleMode when not already looping (same as sendVoiceInput).
        $isActive = $this->chatStatusService->getPresetStatus($presetId);
        $this->agentJobFactory->make()->start($presetId, singleMode: !$isActive);

        // 3. Only now advance the schedule.
        $this->wakeService->recomputeAfterFire($schedule);

        $this->logger->info('WakeDispatch: fired', [
            'schedule_id' => $schedule->getId(),
            'preset_id'   => $presetId,
            'recurring'   => $schedule->isRecurring(),
        ]);
    }
}

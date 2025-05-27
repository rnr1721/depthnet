<?php

namespace App\Jobs;

use App\Contracts\Agent\AgentInterface;
use App\Contracts\OptionsServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;

class ProcessAgentThinking implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum task execution time in seconds
     */
    public $timeout = 0; // ~31 минута

    /**
     * Number of attempts
     */
    public $tries = 1;

    private $lockKey = 'task_lock';
    private $modelActiveKey = 'model_active';
    private $modeKey = 'model_agent_mode';

    /**
     * Execute the job.
     */
    public function handle(AgentInterface $agent, LoggerInterface $log, OptionsServiceInterface $options): void
    {

        $mode = $options->get($this->modeKey, 'looped');
        $active = $options->get($this->modelActiveKey, false);

        // If looped agent not active or single mode we dont run it
        if (!$active || $mode === 'single') {
            $options->set($this->lockKey, false);
            return;
        }

        if ($options->get($this->lockKey) === true) {
            $log->info("ProcessAgentThinking: Skipped due to active lock");
            return;
        }

        $options->set($this->lockKey, true);

        try {
            $log->info("ProcessAgentThinking: Starting thinking cycle");

            $message = $agent->think();

            $log->info("ProcessAgentThinking: Thinking completed", [
                'message_id' => $message->id
            ]);

            ProcessAgentThinkingChain::dispatch()->delay(5);

        } catch (\Throwable $e) {
            $log->error("ProcessAgentThinking: Failed", ['error' => $e->getMessage()]);
        } finally {
            $options->set($this->lockKey, false);
        }
    }

}

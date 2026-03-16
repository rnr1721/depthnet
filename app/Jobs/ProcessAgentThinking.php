<?php

namespace App\Jobs;

use App\Contracts\Agent\AgentJobServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAgentThinking implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum task execution time in seconds (0 = unlimited)
     */
    public $timeout = 0;

    /**
     * Number of attempts
     */
    public $tries = 1;

    /**
     * The preset this thinking cycle belongs to.
     */
    public function __construct(
        public readonly int $presetId
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(AgentJobServiceInterface $agentJobService): void
    {
        $agentJobService->processThinkingCycle($this->presetId);
    }
}

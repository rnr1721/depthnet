<?php

namespace App\Jobs;

use App\Contracts\Agent\AgentJobServiceInterface;
use App\Services\Agent\AgentJobService;
use Illuminate\Support\Facades\Cache;
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
        public readonly int $presetId,
        // Set only for automatic retries after a failed cycle (see AgentJobService)
        public readonly ?string $retryToken = null,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(AgentJobServiceInterface $agentJobService): void
    {
        // A stale auto-retry: manual retry, stop, or a successful cycle happened
        // since it was scheduled — running it now would be an extra cycle.
        if ($this->retryToken !== null
            && Cache::get(AgentJobService::RETRY_TOKEN_PREFIX . $this->presetId) !== $this->retryToken) {
            return;
        }

        $agentJobService->processThinkingCycle($this->presetId);
    }
}

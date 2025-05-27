<?php

namespace App\Jobs;

use App\Contracts\OptionsServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAgentThinkingChain implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum task execution time in seconds
     */
    public $timeout = 0;

    /**
     * Number of attempts
     */
    public $tries = 1;

    /**
     * Execute the job.
     */
    public function handle(OptionsServiceInterface $optionsService): void
    {
        $thinkingDelay = $optionsService->get('model_timeout_between_requests', 15);
        ProcessAgentThinking::dispatch()->delay($thinkingDelay);
    }

}

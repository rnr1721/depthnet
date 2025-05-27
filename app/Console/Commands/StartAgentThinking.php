<?php

namespace App\Console\Commands;

use App\Contracts\OptionsServiceInterface;
use App\Jobs\ProcessAgentThinking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class StartAgentThinking extends Command
{
    protected $signature = 'agent:start';
    protected $description = 'Start agent thinking process';

    public function handle(OptionsServiceInterface $options)
    {
        Artisan::call('queue:restart');
        $options->set('task_lock', false);
        ProcessAgentThinking::dispatch();
        $this->info('Agent thinking process started!');
    }
}

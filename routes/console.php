<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| schedule:run is kept alive by supervisor ([program:laravel-schedule]:
| `while true; schedule:run; sleep 60`), so anything registered here runs
| out of the box on any booted DepthNet instance.
|
*/

// Contract metabolism: tick the engine for every preset whose contract plugin
// is enabled. everyMinute() is a ceiling on cadence — the real pace comes from
// each preset's tick_min_seconds (extra runs coalesce away inside the engine).
// withoutOverlapping() guards at the command level; the runner additionally
// guards each preset with its own contract_tick_{id} lock.
Schedule::command('contract:tick')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

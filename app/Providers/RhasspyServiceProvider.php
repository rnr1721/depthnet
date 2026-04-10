<?php

namespace App\Providers;

use App\Contracts\Integrations\Rhasspy\RhasspyServiceInterface;
use App\Events\AgentSpeakEvent;
use App\Listeners\RhasspySpeakListener;
use App\Services\Integrations\Rhasspy\RhasspyService;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;

class RhasspyServiceProvider extends EventServiceProvider
{
    /**
     * Event → Listener bindings for Rhasspy integration.
     */
    protected $listen = [
        AgentSpeakEvent::class => [
            RhasspySpeakListener::class,
        ],
    ];

    /**
     * Register DI bindings.
     */
    public function register(): void
    {
        $this->app->bind(
            RhasspyServiceInterface::class,
            RhasspyService::class,
        );
    }

    public function boot(): void
    {
        parent::boot();
    }
}

<?php

namespace App\Providers\Capabilities;

use App\Services\Agent\Capabilities\Embedding\Drivers\NovitaEmbeddingProvider;
use App\Services\Agent\Capabilities\Embedding\EmbeddingRegistry;
use App\Services\Agent\Capabilities\Embedding\EmbeddingService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Registers embedding capability providers and binds the registry/service
 * into the Laravel container.
 *
 * To add a new embedding driver:
 *  1. Create the driver class in Drivers/
 *  2. Add a $registry->register(new YourDriver(...)) call in boot()
 *  3. Add the match arm in EmbeddingRegistry::instantiate()
 */
class EmbeddingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EmbeddingRegistry::class, function ($app) {
            return new EmbeddingRegistry(
                $app->make(HttpFactory::class),
                $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(EmbeddingService::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(EmbeddingRegistry::class);
        $http     = $this->app->make(HttpFactory::class);
        $logger   = $this->app->make(LoggerInterface::class);

        // Register Novita as the first available embedding driver.
        // Config values are loaded per-preset from preset_capability_configs at runtime.
        // Here we just register the prototype with empty config.
        $registry->register(new NovitaEmbeddingProvider($http, $logger));

        // When OpenAI or other providers are added:
        // $registry->register(new OpenAiEmbeddingProvider($http, $logger));
    }
}

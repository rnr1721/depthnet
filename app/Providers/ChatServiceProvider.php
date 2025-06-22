<?php

namespace App\Providers;

use App\Contracts\Chat\ChatExporterRegistryInterface;
use App\Contracts\Chat\ChatExporterServiceInterface;
use App\Contracts\Chat\ChatServiceInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Services\Chat\ChatExporterRegistry;
use App\Services\Chat\ChatExporterService;
use App\Services\Chat\ChatService;
use App\Services\Chat\ChatStatusService;
use App\Services\Chat\Exporters\JsonChatExporter;
use App\Services\Chat\Exporters\MarkdownChatExporter;
use App\Services\Chat\Exporters\TxtChatExporter;
use Illuminate\Support\ServiceProvider;

class ChatServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(ChatStatusServiceInterface::class, ChatStatusService::class);
        $this->app->bind(ChatExporterRegistryInterface::class, function ($app) {
            $chatExporterRegistry = new ChatExporterRegistry();
            $chatExporterRegistry->register(new TxtChatExporter());
            $chatExporterRegistry->register(new JsonChatExporter());
            $chatExporterRegistry->register(new MarkdownChatExporter());
            return $chatExporterRegistry;
        });
        $this->app->bind(ChatExporterServiceInterface::class, ChatExporterService::class);

        $this->app->singleton(ChatServiceInterface::class, ChatService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}

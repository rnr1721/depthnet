<?php

namespace App\Providers;

use App\Contracts\Chat\ChatExporterRegistryInterface;
use App\Contracts\Chat\ChatExporterServiceInterface;
use App\Contracts\Chat\ChatServiceInterface;
use App\Contracts\OptionsServiceInterface;
use App\Services\Chat\ChatExporterRegistry;
use App\Services\Chat\ChatExporterService;
use App\Services\Chat\ChatService;
use App\Services\Chat\ChatStaticService;
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
        $this->app->bind(ChatExporterRegistryInterface::class, function ($app) {
            $chatExporterRegistry = new ChatExporterRegistry();
            $chatExporterRegistry->register(new TxtChatExporter());
            $chatExporterRegistry->register(new JsonChatExporter());
            $chatExporterRegistry->register(new MarkdownChatExporter());
            return $chatExporterRegistry;
        });
        $this->app->bind(ChatExporterServiceInterface::class, ChatExporterService::class);

        $this->app->bind(ChatServiceInterface::class, function ($app) {
            $optionsService = $app->get(OptionsServiceInterface::class);
            $currentMode = $optionsService->get('model_agent_mode', 'single');
            if ($currentMode === 'single') {
                return $app->make(ChatStaticService::class);
            } elseif ($currentMode === 'looped') {
                return $app->make(ChatService::class);
            }
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}

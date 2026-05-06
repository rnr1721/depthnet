<?php

namespace App\Providers;

use App\Contracts\Agent\FileStorage\FileQueryServiceInterface;
use App\Contracts\Agent\FileStorage\FileServiceInterface;
use App\Contracts\Agent\FileStorage\FileStorageFactoryInterface;
use App\Contracts\Agent\PresetSandboxServiceInterface;
use App\Contracts\Chat\ChatFileAttachmentServiceInterface;
use App\Services\Agent\FileStorage\FileProcessorRegistry;
use App\Services\Agent\FileStorage\FileQueryService;
use App\Services\Agent\FileStorage\FileService;
use App\Services\Agent\FileStorage\FileStorageFactory;
use App\Services\Agent\FileStorage\LaravelFileStorageService;
use App\Services\Agent\FileStorage\Processors\FallbackFileProcessor;
use App\Services\Agent\FileStorage\Processors\PdfFileProcessor;
use App\Services\Agent\FileStorage\Processors\PlainTextFileProcessor;
use App\Services\Agent\FileStorage\Processors\SpreadsheetFileProcessor;
use App\Services\Agent\FileStorage\SandboxFileStorageService;
use App\Services\Chat\ChatFileAttachmentService;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class FileStorageServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {

        $this->app->singleton(LaravelFileStorageService::class, function ($app) {
            return new LaravelFileStorageService(
                logger: $app->make(LoggerInterface::class),
                disk: 'local',
            );
        });

        $this->app->singleton(SandboxFileStorageService::class, function ($app) {
            return new SandboxFileStorageService(
                sandboxService: $app->make(PresetSandboxServiceInterface::class),
                logger: $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(FileStorageFactoryInterface::class, function ($app) {
            return new FileStorageFactory(
                $app->make(LaravelFileStorageService::class),
                $app->make(SandboxFileStorageService::class),
            );
        });

        // --- Processors ---

        $this->app->singleton(FileProcessorRegistry::class, function ($app) {
            $registry = new FileProcessorRegistry();
            $logger   = $app->make(LoggerInterface::class);

            $registry->register(new PlainTextFileProcessor($logger));
            $registry->register(new PdfFileProcessor($logger));          // когда добавишь smalot/pdfparser
            $registry->register(new SpreadsheetFileProcessor($logger));  // когда добавишь PhpSpreadsheet
            $registry->register(new FallbackFileProcessor($logger));        // всегда последним

            return $registry;
        });

        $this->app->singleton(FileServiceInterface::class, FileService::class);
        $this->app->singleton(FileQueryServiceInterface::class, FileQueryService::class);
        $this->app->singleton(ChatFileAttachmentServiceInterface::class, ChatFileAttachmentService::class);

    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}

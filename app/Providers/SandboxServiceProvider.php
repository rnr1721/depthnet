<?php

namespace App\Providers;

use App\Contracts\Sandbox\ErrorHandlerFactoryInterface;
use App\Contracts\Sandbox\SandboxManagerInterface;
use App\Contracts\Sandbox\SandboxOperationServiceInterface;
use App\Contracts\Sandbox\SandboxServiceInterface;
use App\Services\Sandbox\DockerSandboxManager;
use App\Services\Sandbox\ErrorHandlers\ErrorHandlerFactory;
use App\Services\Sandbox\ErrorHandlers\JavaScriptErrorHandler;
use App\Services\Sandbox\ErrorHandlers\PHPErrorHandler;
use App\Services\Sandbox\ErrorHandlers\PythonErrorHandler;
use App\Services\Sandbox\SandboxOperationService;
use App\Services\Sandbox\SandboxService;
use Illuminate\Support\ServiceProvider;

class SandboxServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(ErrorHandlerFactoryInterface::class, function () {
            $handlerFactory = new ErrorHandlerFactory();
            $handlerFactory
                ->register(new PHPErrorHandler())
                ->register(new PythonErrorHandler())
                ->register(new JavaScriptErrorHandler());
            return $handlerFactory;
        });
        $this->app->bind(SandboxManagerInterface::class, DockerSandboxManager::class);
        $this->app->bind(SandboxServiceInterface::class, SandboxService::class);
        $this->app->singleton(SandboxOperationServiceInterface::class, SandboxOperationService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}

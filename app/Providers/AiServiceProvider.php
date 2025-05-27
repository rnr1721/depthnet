<?php

namespace App\Providers;

use Illuminate\Http\Client\Factory as HttpFactory;
use App\Models\Message;
use App\Services\Agent\Agent;
use App\Services\Agent\ModelRegistry;
use App\Services\Agent\PluginRegistry;
use App\Contracts\Agent\AgentInterface;
use App\Contracts\Agent\CommandExecutorInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\CommandParserInterface;
use App\Contracts\Agent\CommandValidatorInterface;
use Illuminate\Support\ServiceProvider;
use App\Services\Agent\Plugins\PHPPlugin;
use App\Services\Agent\Plugins\MySQLPlugin;
use App\Services\Agent\Plugins\MemoryPlugin;
use Illuminate\Database\ConnectionInterface;
use App\Services\Agent\Plugins\DateTimePlugin;
use App\Contracts\Agent\ModelRegistryInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Services\Agent\Plugins\Services\NotepadService;
use App\Contracts\Agent\Plugins\NotepadServiceInterface;
use App\Contracts\OptionsServiceInterface;
use App\Services\Agent\CommandExecutor;
use App\Services\Agent\CommandInstructionBuilder;
use App\Services\Agent\CommandParser;
use App\Services\Agent\CommandValidator;
use App\Services\Agent\Plugins\DopaminePlugin;
use Psr\Log\LoggerInterface;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {

        $this->app->bind(CommandInstructionBuilderInterface::class, CommandInstructionBuilder::class);

        $this->app->bind(NotepadServiceInterface::class, NotepadService::class);
        $this->app->bind(CommandParserInterface::class, CommandParser::class);
        $this->app->bind(CommandExecutorInterface::class, CommandExecutor::class);
        $this->app->bind(CommandValidatorInterface::class, CommandValidator::class);

        $this->app->singleton(PluginRegistryInterface::class, function ($app) {
            $registry = new PluginRegistry();
            $registry->register(new PHPPlugin());
            // $registry->register(new MySQLPlugin());
            $registry->register(new DateTimePlugin());
            $registry->register($app->make(MemoryPlugin::class));
            $registry->register($app->make(DopaminePlugin::class));
            return $registry;
        });

        $this->app->singleton(ModelRegistryInterface::class, function ($app) {
            $models = config('ai.models');
            $registry = new ModelRegistry();
            foreach ($models as $modelName => $modelConfig) {
                $class = $modelConfig['class'];
                $serverUrl = $modelConfig['server_url'] ?? null;
                $config = $modelConfig['config'] ?? [];
                $isDefault = false;
                if (class_exists($class)) {
                    $registry->register(new $class($app->make(HttpFactory::class), $serverUrl, $config), $isDefault);
                }
            }
            return $registry;
        });

        $this->app->singleton(AgentInterface::class, Agent::class);

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(LoggerInterface $log, ConnectionInterface $db): void
    {

    }
}

<?php

namespace App\Providers;

use Illuminate\Http\Client\Factory as HttpFactory;
use App\Services\Agent\Agent;
use App\Services\Agent\PluginRegistry;
use App\Contracts\Agent\AgentInterface;
use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Agent\CommandExecutorInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\CommandParserInterface;
use App\Contracts\Agent\CommandValidatorInterface;
use Illuminate\Support\ServiceProvider;
use App\Services\Agent\Plugins\PHPPlugin;
use App\Services\Agent\Plugins\MemoryPlugin;
use Illuminate\Database\ConnectionInterface;
use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Services\Agent\AgentJobService;
use App\Services\Agent\CommandExecutor;
use App\Services\Agent\CommandInstructionBuilder;
use App\Services\Agent\CommandParser;
use App\Services\Agent\CommandParserSmart;
use App\Services\Agent\CommandValidator;
use App\Services\Agent\EngineRegistry;
use App\Services\Agent\Engines\ClaudeModel;
use App\Services\Agent\Engines\LocalModel;
use App\Services\Agent\Engines\MockModel;
use App\Services\Agent\Engines\OpenAIModel;
use App\Services\Agent\Plugins\DopaminePlugin;
use App\Services\Agent\Plugins\ShellPlugin;
use App\Services\Agent\PresetRegistry;
use App\Services\Agent\PresetService;
use Psr\Log\LoggerInterface;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AgentJobServiceInterface::class, AgentJobService::class);
        $this->app->bind(CommandInstructionBuilderInterface::class, CommandInstructionBuilder::class);
        $this->app->bind(CommandParserInterface::class, function ($app) {
            $options = $app->make(OptionsServiceInterface::class);
            $parserMode = $options->get('agent_command_parser_mode', 'smart');
            switch ($parserMode) {
                case 'smart':
                    return $app->make(CommandParserSmart::class);
                    break;
            }
            return $app->make(CommandParser::class);
        });
        $this->app->bind(CommandExecutorInterface::class, CommandExecutor::class);
        $this->app->bind(CommandValidatorInterface::class, CommandValidator::class);

        $this->app->singleton(PluginRegistryInterface::class, function ($app) {
            $registry = new PluginRegistry();
            $registry->register($app->make(PHPPlugin::class));
            $registry->register($app->make(MemoryPlugin::class));
            $registry->register($app->make(DopaminePlugin::class));
            $registry->register($app->make(ShellPlugin::class));
            return $registry;
        });

        $this->app->singleton(EngineRegistryInterface::class, function ($app) {
            $httpFactory = $app->make(HttpFactory::class);
            $enginesConfig = $app['config']->get('ai.engines', []);
            $registry = new EngineRegistry($httpFactory, $enginesConfig);
            $this->registerEngines($registry, $httpFactory);

            return $registry;
        });

        $this->app->bind(PresetServiceInterface::class, PresetService::class);
        $this->app->singleton(PresetRegistryInterface::class, PresetRegistry::class);

        $this->app->singleton(AgentInterface::class, Agent::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(LoggerInterface $log, ConnectionInterface $db): void
    {
    }

    /**
     * Register all AI engines based on configuration
     */
    protected function registerEngines(EngineRegistryInterface $registry, HttpFactory $httpFactory): void
    {
        $enginesConfig = config('ai.engines', []);
        $registeredDefault = false;

        // Register each engine if enabled
        foreach ($enginesConfig as $engineName => $engineConfig) {
            if (!($engineConfig['enabled'] ?? false)) {
                continue;
            }

            $engine = $this->createEngine($engineName, $httpFactory, $engineConfig);
            if ($engine) {
                $isDefault = $engineConfig['is_default'] ?? false;
                $registry->register($engine, $isDefault);

                if ($isDefault) {
                    $registeredDefault = true;
                }
            }
        }

        // Set fallback default engine if no default was set
        if (!$registeredDefault) {
            $fallbackEngine = config('ai.global.fallback_engine', 'mock');
            if ($registry->has($fallbackEngine)) {
                $registry->setDefaultEngine($fallbackEngine);
            }
        }
    }

    /**
     * Create an engine instance based on its name and configuration
     */
    protected function createEngine(string $engineName, HttpFactory $httpFactory, array $config): ?object
    {
        $serverUrl = $config['server_url'] ?? null;

        switch ($engineName) {
            case 'mock':
                return new MockModel($httpFactory, $serverUrl, $config);

            case 'openai':
                return new OpenAIModel($httpFactory, $serverUrl, $config);

            case 'claude':
                return new ClaudeModel($httpFactory, $serverUrl, $config);

            case 'local':
                return new LocalModel($httpFactory, $serverUrl, $config);

            default:
                // Log warning about unknown engine
                if ($this->app->bound(LoggerInterface::class)) {
                    $logger = $this->app->make(LoggerInterface::class);
                    $logger->warning("Unknown AI engine: {$engineName}");
                }
                return null;
        }
    }
}

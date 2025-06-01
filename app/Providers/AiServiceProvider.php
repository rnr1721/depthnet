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
use App\Services\Agent\Plugins\DateTimePlugin;
use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Services\Agent\AgentJobService;
use App\Services\Agent\CommandExecutor;
use App\Services\Agent\CommandInstructionBuilder;
use App\Services\Agent\CommandParser;
use App\Services\Agent\CommandValidator;
use App\Services\Agent\EngineRegistry;
use App\Services\Agent\Engines\ClaudeModel;
use App\Services\Agent\Engines\LocalModel;
use App\Services\Agent\Engines\MockModel;
use App\Services\Agent\Engines\OpenAIModel;
use App\Services\Agent\Plugins\DopaminePlugin;
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
        $this->app->bind(CommandParserInterface::class, CommandParser::class);
        $this->app->bind(CommandExecutorInterface::class, CommandExecutor::class);
        $this->app->bind(CommandValidatorInterface::class, CommandValidator::class);

        $this->app->singleton(PluginRegistryInterface::class, function ($app) {
            $registry = new PluginRegistry();
            $registry->register($app->make(PHPPlugin::class));
            $registry->register($app->make(DateTimePlugin::class));
            $registry->register($app->make(MemoryPlugin::class));
            $registry->register($app->make(DopaminePlugin::class));
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
     * Register all AI engines
     */
    protected function registerEngines(EngineRegistryInterface $registry, HttpFactory $httpFactory): void
    {
        // Always register Mock (for testing)
        $mock = new MockModel(
            $httpFactory,
            config('ai.engines.mock.server_url', 'http://localhost:8080'),
            config('ai.engines.mock', [])
        );
        $registry->register($mock, config('ai.engines.mock.is_default', false));

        // Register OpenAI if enabled
        if (config('ai.engines.openai.enabled', false)) {
            $openai = new OpenAIModel(
                $httpFactory,
                config('ai.engines.openai.server_url', 'https://api.openai.com/v1/chat/completions'),
                config('ai.engines.openai', [])
            );
            $registry->register($openai, config('ai.engines.openai.is_default', false));
        }

        // Register Claude if enabled
        if (config('ai.engines.claude.enabled', false)) {
            $claude = new ClaudeModel(
                $httpFactory,
                config('ai.engines.claude.server_url', 'https://api.anthropic.com/v1/messages'),
                config('ai.engines.claude', [])
            );
            $registry->register($claude, config('ai.engines.claude.is_default', false));
        }

        // Register Local models if enabled
        if (config('ai.engines.local.enabled', false)) {
            $local = new LocalModel(
                $httpFactory,
                config('ai.engines.local.server_url', 'http://localhost:11434'),
                config('ai.engines.local', [])
            );
            $registry->register($local, config('ai.engines.local.is_default', false));
        }

        // Set Mock as default if no other engine is set as default
        if (!$registry->getDefaultEngineName()) {
            $registry->setDefaultEngine('mock');
        }
    }
}

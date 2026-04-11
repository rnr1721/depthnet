<?php

namespace App\Providers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AgentActionsHandlerInterface;
use App\Contracts\Agent\AgentInterface;
use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Agent\AgentMessageServiceInterface;
use App\Contracts\Agent\Capabilities\EmbeddingServiceInterface;
use App\Contracts\Agent\Cleanup\PresetCleanupServiceInterface;
use App\Contracts\Agent\CommandExecutorInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\CommandLinterInterface;
use App\Contracts\Agent\CommandParserInterface;
use App\Contracts\Agent\CommandPreProcessorInterface;
use App\Contracts\Agent\CommandPreRunnerInterface;
use App\Contracts\Agent\CommandResultPoolInterface;
use App\Contracts\Agent\ContextBuilder\ContextBuilderFactoryInterface;
use App\Contracts\Agent\Enricher\EnricherFactoryInterface;
use App\Contracts\Agent\EnvironmentInfoServiceInterface;
use App\Contracts\Agent\Goals\GoalServiceInterface;
use App\Contracts\Agent\Journal\JournalServiceInterface;
use App\Contracts\Agent\Mcp\McpClientInterface;
use App\Contracts\Agent\Mcp\McpServerRepositoryInterface;
use App\Contracts\Agent\Memory\MemoryExporterInterface;
use App\Contracts\Agent\Memory\MemoryImporterInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Memory\PersonMemoryServiceInterface;
use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Orchestrator\AgentServiceInterface;
use App\Contracts\Agent\Orchestrator\AgentTaskServiceInterface;
use App\Contracts\Agent\Orchestrator\OrchestratorFactoryInterface;
use App\Contracts\Agent\Orchestrator\OrchestratorInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\PluginManagerInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use App\Contracts\Agent\PresetMetadataServiceInterface;
use App\Contracts\Agent\PresetPromptServiceInterface;
use App\Contracts\Agent\PresetSandboxServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Contracts\Agent\Skills\SkillServiceInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryExporterInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryFactoryInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryImporterInterface;
use App\Contracts\Agent\Workspace\WorkspaceServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Services\Agent\Agent;
use App\Services\Agent\AgentActions;
use App\Services\Agent\AgentActionsHandler;
use App\Services\Agent\AgentJobService;
use App\Services\Agent\AgentMessageService;
use App\Services\Agent\Capabilities\Embedding\Drivers\NovitaEmbeddingProvider;
use App\Services\Agent\Capabilities\Embedding\EmbeddingRegistry;
use App\Services\Agent\Capabilities\Embedding\EmbeddingService;
use App\Services\Agent\Cleanup\PresetCleanupService;
use App\Services\Agent\CommandExecutor;
use App\Services\Agent\CommandInstructionBuilder;
use App\Services\Agent\CommandLinter;
use App\Services\Agent\CommandParser;
use App\Services\Agent\CommandParserSmart;
use App\Services\Agent\CommandPreProcessor;
use App\Services\Agent\CommandPreRunner;
use App\Services\Agent\CommandResultPoolService;
use App\Services\Agent\ContextBuilder\ContextBuilderFactory;
use App\Services\Agent\EngineRegistry;
use App\Services\Agent\Enricher\EnricherFactory;
use App\Services\Agent\EnvironmentInfoService;
use App\Services\Agent\Goals\GoalService;
use App\Services\Agent\Journal\JournalService;
use App\Services\Agent\Mcp\McpClient;
use App\Services\Agent\Mcp\McpServerRepository;
use App\Services\Agent\Providers\ClaudeModel;
use App\Services\Agent\Providers\LocalModel;
use App\Services\Agent\Providers\MockModel;
use App\Services\Agent\Providers\OpenAIModel;
use App\Services\Agent\Memory\TextMemoryExporter;
use App\Services\Agent\Memory\TextMemoryImporter;
use App\Services\Agent\Memory\MemoryService;
use App\Services\Agent\Memory\PersonMemoryService;
use App\Services\Agent\Orchestrator\AgentService;
use App\Services\Agent\Orchestrator\AgentTaskService;
use App\Services\Agent\Orchestrator\OrchestratorFactory;
use App\Services\Agent\Orchestrator\OrchestratorService;
use App\Services\Agent\PlaceholderService;
use App\Services\Agent\PluginManager;
use App\Services\Agent\PluginMetadataService;
use App\Services\Agent\PluginRegistry;
use App\Services\Agent\Plugins\AgentPlugin;
use App\Services\Agent\Plugins\AgentTaskPlugin;
use App\Services\Agent\Plugins\BeingPlugin;
use App\Services\Agent\Plugins\CodeCraftPlugin;
use App\Services\Agent\Plugins\DopaminePlugin;
use App\Services\Agent\Plugins\GoalPlugin;
use App\Services\Agent\Plugins\HeartPlugin;
use App\Services\Agent\Plugins\JournalPlugin;
use App\Services\Agent\Plugins\McpPlugin;
use App\Services\Agent\Plugins\MemoryPlugin;
use App\Services\Agent\Plugins\MoodPlugin;
use App\Services\Agent\Plugins\NodePlugin;
use App\Services\Agent\Plugins\PersonPlugin;
use App\Services\Agent\Plugins\PHPPlugin;
use App\Services\Agent\Plugins\PlaywrightBrowserPlugin;
use App\Services\Agent\Plugins\PromptPlugin;
use App\Services\Agent\Plugins\PuppeteerBrowserPlugin;
use App\Services\Agent\Plugins\PythonPlugin;
use App\Services\Agent\Plugins\RagQueryPlugin;
use App\Services\Agent\Plugins\Related\VectorMemory\TfIdfService;
use App\Services\Agent\Plugins\RhythmPlugin;
use App\Services\Agent\Plugins\SandboxPlugin;
use App\Services\Agent\Plugins\ShellPlugin;
use App\Services\Agent\Plugins\SkillPlugin;
use App\Services\Agent\Plugins\VectorMemoryPlugin;
use App\Services\Agent\Plugins\WorkspacePlugin;
use App\Services\Agent\PresetMetadataService;
use App\Services\Agent\PresetPromptService;
use App\Services\Agent\PresetRegistry;
use App\Services\Agent\PresetSandboxService;
use App\Services\Agent\PresetService;
use App\Services\Agent\Providers\DeepSeekModel;
use App\Services\Agent\Providers\FireworksModel;
use App\Services\Agent\Providers\GeminiModel;
use App\Services\Agent\Providers\NovitaModel;
use App\Services\Agent\ShortcodeManagerService;
use App\Services\Agent\ShortcodeScopeResolverService;
use App\Services\Agent\Skills\SkillService;
use App\Services\Agent\VectorMemory\EmbeddingAssociativeVectorMemoryService;
use App\Services\Agent\VectorMemory\EmbeddingVectorMemoryService;
use App\Services\Agent\VectorMemory\VectorMemoryAssociativeService;
use App\Services\Agent\VectorMemory\VectorMemoryExporter;
use App\Services\Agent\VectorMemory\VectorMemoryFactory;
use App\Services\Agent\VectorMemory\VectorMemoryImporter;
use App\Services\Agent\VectorMemory\VectorMemoryService;
use App\Services\Agent\Workspace\WorkspaceService;
use Illuminate\Cache\CacheManager;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {

        $this->app->bind(McpClientInterface::class, McpClient::class);
        $this->app->bind(McpServerRepositoryInterface::class, McpServerRepository::class);

        $this->app->singleton(CommandResultPoolInterface::class, CommandResultPoolService::class);

        $options = $this->app->get(OptionsServiceInterface::class);
        $this->app->bind(MemoryExporterInterface::class, TextMemoryExporter::class);
        $this->app->bind(MemoryImporterInterface::class, TextMemoryImporter::class);
        $this->app->bind(VectorMemoryImporterInterface::class, VectorMemoryImporter::class);
        $this->app->bind(VectorMemoryExporterInterface::class, VectorMemoryExporter::class);

        $this->app->singleton(MemoryServiceInterface::class, MemoryService::class);
        $this->app->singleton(PersonMemoryServiceInterface::class, PersonMemoryService::class);

        //$this->app->bind(RagContextEnricherInterface::class, RagContextEnricher::class);

        $this->app->singleton(EnricherFactoryInterface::class, EnricherFactory::class);

        $this->app->bind(TfIdfServiceInterface::class, TfIdfService::class);

        $this->app->singleton(JournalServiceInterface::class, JournalService::class);

        // EmbeddingRegistry: singleton so all drivers are registered once.
        $this->app->singleton(EmbeddingRegistry::class, function ($app) {
            $registry = new EmbeddingRegistry(
                $app->make(HttpFactory::class),
                $app->make(LoggerInterface::class),
            );

            // Register Novita as the first available embedding driver.
            // Add new drivers here as they become available.
            $registry->register(
                new NovitaEmbeddingProvider(
                    $app->make(HttpFactory::class),
                    $app->make(LoggerInterface::class),
                )
            );

            // $registry->register(new OpenAiEmbeddingProvider(...));

            return $registry;
        });

        $this->app->singleton(EmbeddingServiceInterface::class, EmbeddingService::class);

        // VectorMemoryFactory
        $this->app->singleton(VectorMemoryFactoryInterface::class, function ($app) {
            return new VectorMemoryFactory(
                flatTfidf:             $app->make(VectorMemoryService::class),
                associativeTfidf:      $app->make(VectorMemoryAssociativeService::class),
                flatEmbedding:         $app->make(EmbeddingVectorMemoryService::class),
                associativeEmbedding:  $app->make(EmbeddingAssociativeVectorMemoryService::class),
            );
        });

        $this->app->bind(WorkspaceServiceInterface::class, WorkspaceService::class);

        $this->app->singleton(AgentServiceInterface::class, AgentService::class);
        $this->app->singleton(OrchestratorInterface::class, OrchestratorService::class);
        $this->app->bind(OrchestratorFactoryInterface::class, OrchestratorFactory::class);
        $this->app->singleton(AgentTaskServiceInterface::class, AgentTaskService::class);
        $this->app->singleton(GoalServiceInterface::class, GoalService::class);

        $this->app->singleton(SkillServiceInterface::class, SkillService::class);

        $this->app->bind(PresetSandboxServiceInterface::class, PresetSandboxService::class);
        $this->app->bind(ContextBuilderFactoryInterface::class, ContextBuilderFactory::class);
        $this->app->singleton(ShortcodeScopeResolverServiceInterface::class, ShortcodeScopeResolverService::class);
        $this->app->singleton(PlaceholderServiceInterface::class, PlaceholderService::class);
        $this->app->singleton(ShortcodeManagerServiceInterface::class, ShortcodeManagerService::class);
        $this->app->bind(EnvironmentInfoServiceInterface::class, EnvironmentInfoService::class);

        $this->app->singleton(AgentJobServiceInterface::class, AgentJobService::class);
        $this->app->singleton(CommandInstructionBuilderInterface::class, CommandInstructionBuilder::class);
        $this->app->bind(CommandPreProcessorInterface::class, CommandPreProcessor::class);

        $this->app->bind(CommandParserInterface::class, function ($app) use ($options) {
            $parserMode = $options->get('agent_command_parser_mode', 'smart');
            switch ($parserMode) {
                case 'smart':
                    return $app->make(CommandParserSmart::class);
                    break;
            }
            return $app->make(CommandParser::class);
        });
        $this->app->bind(CommandExecutorInterface::class, CommandExecutor::class);
        $this->app->bind(CommandLinterInterface::class, CommandLinter::class);

        $this->app->bind(CommandPreRunnerInterface::class, CommandPreRunner::class);

        $this->app->singleton(PluginRegistryInterface::class, function ($app) {

            $registry = $app->make(PluginRegistry::class);
            $this->registerPlugins($registry, $app);

            return $registry;
        });

        $this->app->bind(PresetMetadataServiceInterface::class, PresetMetadataService::class);
        $this->app->bind(PluginMetadataServiceInterface::class, PluginMetadataService::class);

        $this->app->singleton(PluginManagerInterface::class, PluginManager::class);

        $this->app->singleton(EngineRegistryInterface::class, function ($app) {
            $httpFactory = $app->make(HttpFactory::class);
            $logger = $app->make(LoggerInterface::class);
            $cache = $app->make(CacheManager::class);
            $enginesConfig = $app['config']->get('ai.engines', []);
            $registry = new EngineRegistry($httpFactory, $logger, $cache, $enginesConfig);
            $this->registerEngines($registry, $httpFactory, $logger, $cache);

            return $registry;
        });
        $this->app->bind(AgentMessageServiceInterface::class, AgentMessageService::class);
        $this->app->singleton(PresetPromptServiceInterface::class, PresetPromptService::class);
        $this->app->bind(PresetServiceInterface::class, PresetService::class);
        $this->app->singleton(PresetRegistryInterface::class, PresetRegistry::class);



        $this->app->singleton(AgentActionsHandlerInterface::class, AgentActionsHandler::class);
        $this->app->singleton(AgentActionsInterface::class, AgentActions::class);
        $this->app->singleton(AgentInterface::class, Agent::class);

        $this->app->singleton(PresetCleanupServiceInterface::class, PresetCleanupService::class);

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(LoggerInterface $log, ConnectionInterface $db): void
    {
        $this->initializePluginConfigurations();
    }

    /**
     * Register all plugins with the registry
     */
    protected function registerPlugins(PluginRegistryInterface $registry, $app): void
    {


        // built-in + composer packages
        $allPlugins = $this->getAllAvailablePlugins();

        foreach ($allPlugins as $pluginClass) {
            try {
                $plugin = $app->make($pluginClass);
                $registry->register($plugin);
            } catch (\Throwable $e) {
                if ($app->bound(LoggerInterface::class)) {
                    $logger = $app->make(LoggerInterface::class);
                    $logger->error("Failed to register plugin {$pluginClass}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Get all available plugins from built-in and composer packages
     *
     * @return array
     */
    protected function getAllAvailablePlugins(): array
    {
        $plugins = $this->getBuiltInPlugins();

        $composerPlugins = $this->discoverComposerPlugins();
        $plugins = array_merge($plugins, $composerPlugins);

        return $this->filterAvailablePlugins($plugins);
    }

    /**
     * Get built-in plugins
     *
     * @return array
     */
    protected function getBuiltInPlugins(): array
    {
        return [
            AgentPlugin::class,
            VectorMemoryPlugin::class,
            MemoryPlugin::class,
            RagQueryPlugin::class,
            JournalPlugin::class,
            PersonPlugin::class,
            SandboxPlugin::class,
            PromptPlugin::class,
            ShellPlugin::class,
            PHPPlugin::class,
            NodePlugin::class,
            PythonPlugin::class,
            DopaminePlugin::class,
            MoodPlugin::class,
            PuppeteerBrowserPlugin::class,
            PlaywrightBrowserPlugin::class,
            WorkspacePlugin::class,
            GoalPlugin::class,
            AgentTaskPlugin::class,
            SkillPlugin::class,
            McpPlugin::class,
            CodeCraftPlugin::class,
            HeartPlugin::class,
            BeingPlugin::class,
            RhythmPlugin::class
        ];
    }

    /**
     * Discover plugins from composer packages
     *
     * @return array
     */
    protected function discoverComposerPlugins(): array
    {
        $discovered = [];

        // Method 1: From config - manual registration
        $configPlugins = config('ai.plugins.composer', []);
        $discovered = array_merge($discovered, $configPlugins);

        // Method 2: Tagged services - automatic registration
        try {
            $taggedPlugins = $this->app->tagged('agent.plugins');
            foreach ($taggedPlugins as $plugin) {
                if (is_object($plugin)) {
                    $discovered[] = get_class($plugin);
                } elseif (is_string($plugin)) {
                    $discovered[] = $plugin;
                }
            }
        } catch (\Exception $e) {
            // This is normal, no tagged services found
        }

        return array_unique($discovered);
    }

    /**
     * Filter plugins by availability configuration
     *
     * @param array $plugins
     * @return array
     */
    protected function filterAvailablePlugins(array $plugins): array
    {
        $available = [];

        foreach ($plugins as $pluginClass) {
            $pluginName = $this->getPluginNameFromClass($pluginClass);

            if (config("ai.plugins.{$pluginName}.available", true)) {
                $available[] = $pluginClass;
            }
        }

        return $available;
    }

    /**
     * Extract plugin name from class name
     *
     * @param string $pluginClass
     * @return string
     */
    protected function getPluginNameFromClass(string $pluginClass): string
    {
        $className = class_basename($pluginClass);
        return strtolower(str_replace('Plugin', '', $className));
    }

    /**
     * Register engines with discovery support
     */
    protected function registerEngines(
        EngineRegistryInterface $registry,
        HttpFactory $httpFactory,
        LoggerInterface $logger,
        CacheManager $cache
    ): void {
        $enginesConfig = config('ai.engines', []);
        $registeredDefault = false;

        foreach ($enginesConfig as $engineName => $engineConfig) {
            if (!($engineConfig['enabled'] ?? false)) {
                continue;
            }

            $engine = $this->createEngine($engineName, $httpFactory, $logger, $cache, $engineConfig);
            if ($engine) {
                $isDefault = $engineConfig['is_default'] ?? false;
                $registry->register($engine, $isDefault);

                if ($isDefault) {
                    $registeredDefault = true;
                }
            }
        }

        if (!$registeredDefault) {
            $fallbackEngine = config('ai.global.fallback_engine', 'mock');
            if ($registry->has($fallbackEngine)) {
                $registry->setDefaultEngine($fallbackEngine);
            }
        }
    }

    /**
     * Create an engine instance with composer support
     */
    protected function createEngine(
        string $engineName,
        HttpFactory $httpFactory,
        LoggerInterface $logger,
        CacheManager $cache,
        array $config
    ): ?object {

        if (isset($config['class'])) {
            try {
                $engineClass = $config['class'];
                if (class_exists($engineClass)) {
                    return new $engineClass($httpFactory, $logger, $cache, $config);
                }
            } catch (\Throwable $e) {
                $logger->warning("Failed to create custom engine {$engineName}: " . $e->getMessage());
            }
        }

        switch ($engineName) {
            case 'mock':
                return new MockModel($httpFactory, $logger, $cache, $config);
            case 'openai':
                return new OpenAIModel($httpFactory, $logger, $cache, $config);
            case 'claude':
                return new ClaudeModel($httpFactory, $logger, $cache, $config);
            case 'local':
                return new LocalModel($httpFactory, $logger, $cache, $config);
            case 'novita':
                return new NovitaModel($httpFactory, $logger, $cache, $config);
            case 'gemini':
                return new GeminiModel($httpFactory, $logger, $cache, $config);
            case 'fireworks':
                return new FireworksModel($httpFactory, $logger, $cache, $config);
            case 'deepseek':
                return new DeepSeekModel($httpFactory, $logger, $cache, $config);
            default:
                $logger->warning("Unknown AI engine: {$engineName}");
                return null;
        }
    }

    /**
     * Initialize plugin configurations
     *
     * @return void
     */
    protected function initializePluginConfigurations(): void
    {
        if (!$this->app->bound(PluginManager::class)) {
            return;
        }

        try {
            $pluginManager = $this->app->make(PluginManager::class);

            // Test all plugins on boot if in debug mode
            if (config('ai.debug.enabled', false)) {
                $testResults = $pluginManager->testAllPlugins();

                if ($this->app->bound(LoggerInterface::class)) {
                    $logger = $this->app->make(LoggerInterface::class);
                    $logger->debug('Plugin test results on boot', $testResults);
                }
            }

            if (config('ai.logging.enabled', true)) {
                $stats = $pluginManager->getPluginStatistics();

                if ($this->app->bound(LoggerInterface::class)) {
                    $logger = $this->app->make(LoggerInterface::class);
                    $logger->info('Plugin system initialized', $stats);
                }
            }

        } catch (\Throwable $e) {
            if ($this->app->bound(LoggerInterface::class)) {
                $logger = $this->app->make(LoggerInterface::class);
                $logger->error('Failed to initialize plugin configurations: ' . $e->getMessage());
            }
        }
    }

}

<?php

namespace App\Services\Agent;

use App\Contracts\Agent\Models\AIModelEngineInterface;
use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Exceptions\EngineRegistryException;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * Registry for AI model engines with dynamic configuration
 *
 * This registry is engine-agnostic and gets all metadata from the engines themselves
 * and configuration files. No hardcoded engine-specific logic here.
 */
class EngineRegistry implements EngineRegistryInterface
{
    /**
     * @var AIModelEngineInterface[]
     */
    protected array $engines = [];

    /**
     * @var string|null
     */
    protected ?string $defaultEngine = null;

    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
        protected CacheManager $cache,
        protected array $configEngines = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function register(AIModelEngineInterface $engine, bool $isDefault = false): self
    {
        $name = $engine->getName();
        $this->engines[$name] = $engine;

        if ($isDefault || $this->defaultEngine === null) {
            $this->defaultEngine = $name;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function has(string $name): bool
    {
        return isset($this->engines[$name]);
    }

    /**
    * @inheritDoc
    */
    public function get(?string $name = null): AIModelEngineInterface
    {
        $engineName = $name ?? $this->defaultEngine;

        if ($engineName === null || !$this->has($engineName)) {
            throw new EngineRegistryException("Engine '$engineName' not found");
        }

        return $this->engines[$engineName];
    }

    /**
     * @inheritDoc
     */
    public function getEngine(?string $name = null): ?AIModelEngineInterface
    {
        try {
            return $this->get($name);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        return $this->engines;
    }

    /**
     * @inheritDoc
     */
    public function getAvailableEngines(): array
    {
        $available = [];

        foreach ($this->configEngines as $engineName => $config) {
            $isEnabled = $config['enabled'] ?? false;
            $isRegistered = $this->has($engineName);

            $available[$engineName] = [
                'name' => $engineName,
                'enabled' => $isEnabled && $isRegistered,
                'display_name' => $this->getEngineDisplayName($engineName),
                'description' => $this->getEngineDescription($engineName),
                'config_fields' => $this->getEngineConfigFields($engineName),
                'default_config' => $this->getEngineDefaults($engineName),
                'recommended_presets' => $this->getRecommendedPresets($engineName),
                'engine_instance' => $isRegistered ? $this->engines[$engineName] : null
            ];
        }

        return $available;
    }

    /**
     * @inheritDoc
     */
    public function getEngineDisplayName(string $engineName): string
    {
        // First try from config
        $engineConfig = $this->configEngines[$engineName] ?? [];
        $configName = $engineConfig['display_name'] ?? null;
        if ($configName) {
            return $configName;
        }

        // Then try from engine instance
        if ($this->has($engineName)) {
            $engine = $this->engines[$engineName];
            if (method_exists($engine, 'getDisplayName')) {
                return $engine->getDisplayName();
            }
        }

        // Fallback to formatted name
        return ucfirst(str_replace('_', ' ', $engineName));
    }

    /**
     * @inheritDoc
     */
    public function getEngineDescription(string $engineName): string
    {
        // First try from config
        $engineConfig = $this->configEngines[$engineName] ?? [];
        $configDescription = $engineConfig['description'] ?? null;
        if ($configDescription) {
            return $configDescription;
        }

        // Then try from engine instance
        if ($this->has($engineName)) {
            $engine = $this->engines[$engineName];
            if (method_exists($engine, 'getDescription')) {
                return $engine->getDescription();
            }
        }

        return 'AI engine';
    }

    /**
     * @inheritDoc
     */
    public function getEngineConfigFields(string $engineName): array
    {
        $fields = [];

        // First try to get from engine instance
        if ($this->has($engineName)) {
            $engine = $this->engines[$engineName];
            if (method_exists($engine, 'getConfigFields')) {
                $fields = $engine->getConfigFields();
            }
        }

        // If no fields from engine, try from config
        if (empty($fields)) {
            $engineConfig = $this->configEngines[$engineName] ?? [];
            $fields = $engineConfig['config_fields'] ?? [];
        }

        // Always add system_prompt as a common field if not present
        if (!isset($fields['system_prompt'])) {
            $fields['system_prompt'] = [
                'type' => 'textarea',
                'label' => 'System prompt',
                'description' => 'Instructions for AI on how to behave and respond',
                'placeholder' => 'You are a useful AI assistant...',
                'required' => false,
                'rows' => 6
            ];
        }

        return $fields;
    }

    /**
     * @inheritDoc
     */
    public function getRecommendedPresets(string $engineName): array
    {
        // First try from engine instance
        if ($this->has($engineName)) {
            $engine = $this->engines[$engineName];
            if (method_exists($engine, 'getRecommendedPresets')) {
                return $engine->getRecommendedPresets();
            }
        }

        // Then try from config
        $engineConfig = $this->configEngines[$engineName] ?? [];
        $configPresets = $engineConfig['recommended_presets'] ?? null;
        if ($configPresets) {
            return $configPresets;
        }

        // Default fallback presets
        return [
            [
                'name' => 'Balanced',
                'description' => 'Universal settings for most tasks',
                'config' => []
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function getEngineDefaults(string $engineName): array
    {
        // First try to get from registered engine
        if ($this->has($engineName)) {
            $engine = $this->engines[$engineName];
            if (method_exists($engine, 'getDefaultConfig')) {
                return $engine->getDefaultConfig();
            }
        }

        // Get from config file, excluding metadata fields
        $configDefaults = $this->configEngines[$engineName] ?? [];

        // Remove metadata fields that shouldn't be part of default config
        $metadataFields = ['enabled', 'is_default', 'display_name', 'description', 'config_fields', 'recommended_presets'];

        return array_filter($configDefaults, function ($key) use ($metadataFields) {
            return !in_array($key, $metadataFields);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @inheritDoc
     */
    public function getDefaultEngineName(): ?string
    {
        return $this->defaultEngine;
    }

    /**
     * @inheritDoc
     */
    public function setDefaultEngine(string $name): self
    {
        if (!$this->has($name)) {
            throw new EngineRegistryException("Engine '$name' not found");
        }

        $this->defaultEngine = $name;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function validateEngineConfig(string $engineName, array $config): array
    {
        // First try engine's own validation
        if ($this->has($engineName)) {
            $engine = $this->engines[$engineName];
            if (method_exists($engine, 'validateConfig')) {
                return $engine->validateConfig($config);
            }
        }

        // Fallback to generic validation based on config fields
        $errors = [];
        $fields = $this->getEngineConfigFields($engineName);

        foreach ($fields as $fieldName => $fieldConfig) {
            $value = $config[$fieldName] ?? null;

            if (($fieldConfig['required'] ?? false) && empty($value)) {
                $errors[$fieldName] = "The '{$fieldConfig['label']}' field is required";
                continue;
            }

            if ($value === null || $value === '') {
                continue; // Skip validation for empty optional fields
            }

            switch ($fieldConfig['type']) {
                case 'number':
                    if (!is_numeric($value)) {
                        $errors[$fieldName] = "The '{$fieldConfig['label']}' must be a number";
                        break;
                    }
                    if (isset($fieldConfig['min']) && $value < $fieldConfig['min']) {
                        $errors[$fieldName] = "The value must be at least {$fieldConfig['min']}";
                    }
                    if (isset($fieldConfig['max']) && $value > $fieldConfig['max']) {
                        $errors[$fieldName] = "The value must be no more than {$fieldConfig['max']}";
                    }
                    break;
                case 'url':
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        $errors[$fieldName] = "Incorrect URL";
                    }
                    break;
            }
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function testEngineConnection(string $engineName): array
    {
        try {
            $engine = $this->get($engineName);

            if (method_exists($engine, 'testConnection')) {
                $startTime = microtime(true);
                $result = $engine->testConnection();
                $responseTime = round((microtime(true) - $startTime) * 1000, 2);

                return [
                    'success' => $result,
                    'response_time' => $responseTime
                ];
            }

            return [
                'success' => true,
                'response_time' => 0,
                'message' => 'Engine registered but no test method available'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getEngineStats(): array
    {
        $stats = [
            'total_engines' => count($this->engines),
            'available_engines' => count(array_filter($this->getAvailableEngines(), fn ($e) => $e['enabled'])),
            'default_engine' => $this->defaultEngine,
            'engines' => []
        ];

        foreach ($this->engines as $name => $engine) {
            $stats['engines'][$name] = [
                'name' => $name,
                'class' => get_class($engine),
                'is_default' => $name === $this->defaultEngine,
                'display_name' => $this->getEngineDisplayName($name)
            ];
        }

        return $stats;
    }

    /**
     * @inheritDoc
     */
    public function createInstance(string $engineName, array $config = []): AIModelEngineInterface
    {
        if (!$this->has($engineName)) {
            throw new EngineRegistryException("Engine '$engineName' not found");
        }

        $baseEngine = $this->engines[$engineName];
        $engineClass = get_class($baseEngine);

        // Get default config
        $defaultConfig = $this->getEngineDefaults($engineName);
        $mergedConfig = array_merge($defaultConfig, $config);

        $instance = new $engineClass(
            $this->http,
            $this->logger,
            $this->cache,
            $mergedConfig
        );
        return $instance;
    }

    /**
     * @inheritDoc
     */
    public function isEngineEnabled(string $engineName): bool
    {
        $engineConfig = $this->configEngines[$engineName] ?? [];
        return $engineConfig['enabled'] ?? false;
    }

    /**
     * @inheritDoc
     */
    public function getEnabledEngines(): array
    {
        return array_filter($this->getAvailableEngines(), fn ($engine) => $engine['enabled']);
    }
}

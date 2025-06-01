<?php

namespace App\Contracts\Agent\Models;

/**
 * Interface for AI model engine registry with dynamic configuration
 *
 * Provides centralized registry for AI engines with configuration management,
 * validation, testing, and instance creation capabilities. The registry is
 * engine-agnostic and gets metadata from engines and configuration files.
 */
interface EngineRegistryInterface
{
    /**
     * Register an AI model engine in the registry
     *
     * @param AIModelEngineInterface $engine Engine instance to register
     * @param bool $isDefault Whether this engine should be set as default
     * @return self For method chaining
     */
    public function register(AIModelEngineInterface $engine, bool $isDefault = false): self;

    /**
     * Check if engine is registered in the registry
     *
     * @param string $name Engine name
     * @return bool True if engine exists
     */
    public function has(string $name): bool;

    /**
     * Get engine by name or return default engine
     *
     * @param string|null $name Engine name or null for default
     * @return AIModelEngineInterface The engine instance
     * @throws \Exception When engine not found
     */
    public function get(?string $name = null): AIModelEngineInterface;

    /**
     * Get engine by name or return null if not found
     *
     * @param string|null $name Engine name or null for default
     * @return AIModelEngineInterface|null The engine instance or null
     */
    public function getEngine(?string $name = null): ?AIModelEngineInterface;

    /**
     * Get all registered engines
     *
     * @return array<string, AIModelEngineInterface> Array of engine name => engine instance
     */
    public function all(): array;

    /**
     * Get all available engines with their configuration metadata
     *
     * @return array Array of engines with metadata including enabled status, config fields, etc.
     */
    public function getAvailableEngines(): array;

    /**
     * Get the name of the default engine
     *
     * @return string|null Default engine name or null if none set
     */
    public function getDefaultEngineName(): ?string;

    /**
     * Set the default engine
     *
     * @param string $name Engine name to set as default
     * @return self For method chaining
     * @throws \Exception When engine not found
     */
    public function setDefaultEngine(string $name): self;

    /**
     * Get default configuration for an engine
     *
     * @param string $engineName Engine name
     * @return array Default configuration array
     */
    public function getEngineDefaults(string $engineName): array;

    /**
     * Get display name for engine from config or engine instance
     *
     * @param string $engineName Engine name
     * @return string Human-readable display name
     */
    public function getEngineDisplayName(string $engineName): string;

    /**
     * Get description for engine from config or engine instance
     *
     * @param string $engineName Engine name
     * @return string Engine description
     */
    public function getEngineDescription(string $engineName): string;

    /**
     * Get configuration fields metadata for engine
     *
     * @param string $engineName Engine name
     * @return array Configuration fields with type, validation rules, etc.
     */
    public function getEngineConfigFields(string $engineName): array;

    /**
     * Get recommended presets for engine
     *
     * @param string $engineName Engine name
     * @return array Array of recommended preset configurations
     */
    public function getRecommendedPresets(string $engineName): array;

    /**
     * Validate engine configuration against engine rules
     *
     * @param string $engineName Engine name
     * @param array $config Configuration to validate
     * @return array Validation errors (empty array if valid)
     */
    public function validateEngineConfig(string $engineName, array $config): array;

    /**
     * Test engine connection and functionality
     *
     * @param string $engineName Engine name
     * @return array Test results with success status, response time, and error info
     */
    public function testEngineConnection(string $engineName): array;

    /**
     * Get comprehensive engine statistics
     *
     * @return array Statistics including totals, engine details, and default info
     */
    public function getEngineStats(): array;

    /**
     * Create configured instance of engine with custom configuration
     *
     * @param string $engineName Engine name
     * @param array $config Custom configuration to merge with defaults
     * @return AIModelEngineInterface Configured engine instance
     * @throws \Exception When engine not found
     */
    public function createInstance(string $engineName, array $config = []): AIModelEngineInterface;

    /**
     * Check if engine is enabled in application configuration
     *
     * @param string $engineName Engine name
     * @return bool True if engine is enabled
     */
    public function isEngineEnabled(string $engineName): bool;

    /**
     * Get only enabled engines with their metadata
     *
     * @return array Array of enabled engines with full metadata
     */
    public function getEnabledEngines(): array;
}

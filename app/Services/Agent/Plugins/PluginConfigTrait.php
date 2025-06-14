<?php

namespace App\Services\Agent\Plugins;

/**
 * Trait for plugin configuration functionality
 * Handles configuration from database, not from config files
 */
trait PluginConfigTrait
{
    protected array $config = [];
    protected bool $enabled = true;

    /**
     * Get configuration fields for the plugin
     * Override in specific plugins to define their fields
     *
     * @return array
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Plugin',
                'description' => 'Enable or disable this plugin',
                'required' => false
            ]
        ];
    }

    /**
     * Validate plugin configuration
     * Override in specific plugins for custom validation
     *
     * @param array $config
     * @return array
     */
    public function validateConfig(array $config): array
    {
        return [];
    }

    /**
     * Update plugin configuration
     *
     * @param array $newConfig
     * @return void
     */
    public function updateConfig(array $newConfig): void
    {
        $this->config = array_merge($this->config, $newConfig);

        // Update enabled status if provided
        if (isset($newConfig['enabled'])) {
            $this->enabled = (bool) $newConfig['enabled'];
        }
    }

    /**
     * Get current plugin configuration
     *
     * @return array
     */
    public function getConfig(): array
    {
        return array_merge($this->config, [
            'enabled' => $this->enabled
        ]);
    }

    /**
     * Get default configuration for the plugin
     * Override in specific plugins to define defaults
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'enabled' => true
        ];
    }

    /**
     * Test if plugin is properly configured and working
     * Override in specific plugins for actual testing
     *
     * @return boolean
     */
    public function testConnection(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if plugin is enabled
     *
     * @return boolean
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable or disable plugin
     *
     * @param boolean $enabled
     * @return void
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Initialize configuration with defaults only
     * Configuration from database will be applied by PluginManager
     *
     * @return void
     */
    protected function initializeConfig(): void
    {
        $defaultConfig = $this->getDefaultConfig();
        $this->config = $defaultConfig;
        $this->enabled = $defaultConfig['enabled'] ?? true;
    }
}

<?php

namespace App\Services\Agent\Plugins\Traits;

trait PluginMethodTrait
{
    /**
     * Check if the plugin has a specific method.
     *
     * @param string $method The method name to check.
     * @return bool True if the method exists and is not a configuration or main interface method, false otherwise.
     */
    public function hasMethod(string $method): bool
    {
        // Exclude main interface and configuration methods
        $excludedMethods = [
            'getName', 'getDescription', 'execute', 'hasMethod', 'callMethod',
            'getAvailableMethods', 'setCurrentPreset', 'getInstructions',
            'getMergeSeparator', 'getConfigFields', 'validateConfig',
            'updateConfig', 'getConfig', 'getDefaultConfig', 'testConnection',
            'isEnabled', 'setEnabled', 'initializeConfig'
        ];

        return method_exists($this, $method) && !in_array($method, $excludedMethods);
    }

    /**
     * Call a method on the plugin if it exists.
     *
     * @param string $method The method name to call.
     * @param string $content The content to pass to the method.
     * @return string The result of the method call.
     * @throws \BadMethodCallException If the method does not exist.
     */
    public function callMethod(string $method, string $content): string
    {
        if (!$this->hasMethod($method)) {
            throw new \BadMethodCallException("Method '$method' does not exist in " . static::class);
        }
        return $this->$method($content);
    }

    /**
     * Get a list of available methods in the plugin, excluding main interface and configuration methods.
     *
     * @return array List of method names that are available for use.
     */
    public function getAvailableMethods(): array
    {
        $methods = get_class_methods($this);
        $excludedMethods = [
            'getName', 'getDescription', 'execute', 'hasMethod', 'callMethod',
            'getAvailableMethods', 'setCurrentPreset', 'getInstructions',
            'getMergeSeparator', 'getConfigFields', 'validateConfig',
            'updateConfig', 'getConfig', 'getDefaultConfig', 'testConnection',
            'isEnabled', 'setEnabled', 'initializeConfig'
        ];

        return array_filter($methods, function ($method) use ($excludedMethods) {
            return !in_array($method, $excludedMethods);
        });
    }
}

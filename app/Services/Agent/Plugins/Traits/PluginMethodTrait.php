<?php

namespace App\Services\Agent\Plugins\Traits;

use App\Models\AiPreset;

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
        $excludedMethods = [
            'getName', 'getDescription', 'execute', 'pluginReady',
            'hasMethod', 'callMethod', 'getAvailableMethods',
            'getInstructions', 'getMergeSeparator', 'getSelfClosingTags',
            'getConfigFields', 'validateConfig', 'updateConfig',
            'getConfig', 'getDefaultConfig', 'testConnection',
            'isEnabled', 'setEnabled', 'initializeConfig',
            'getCustomSuccessMessage', 'getCustomErrorMessage',
            'canBeMerged', 'getPluginExecutionMeta',
        ];

        return method_exists($this, $method)
            && !in_array($method, $excludedMethods);
    }

    /**
     * Call a method on the plugin if it exists.
     *
     * @param string $method The method name to call.
     * @param string $content The content to pass to the method.
     * @return AiPreset $preset Preset for work
     * @return string The result of the method call.
     * @throws \BadMethodCallException If the method does not exist.
     */
    public function callMethod(string $method, string $content, AiPreset $preset): string
    {
        if (!$this->hasMethod($method)) {
            throw new \BadMethodCallException(
                "Method '{$method}' does not exist in " . static::class
            );
        }
        return $this->{$method}($content, $preset);
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
            'getName', 'getDescription', 'execute', 'pluginReady',
            'hasMethod', 'callMethod', 'getAvailableMethods',
            'getInstructions', 'getMergeSeparator', 'getSelfClosingTags',
            'getConfigFields', 'validateConfig', 'updateConfig',
            'getConfig', 'getDefaultConfig', 'testConnection',
            'isEnabled', 'setEnabled', 'initializeConfig',
            'getCustomSuccessMessage', 'getCustomErrorMessage',
            'canBeMerged', 'getPluginExecutionMeta',
        ];

        return array_values(array_filter(
            $methods,
            fn ($m) => !in_array($m, $excludedMethods) && !str_starts_with($m, '__')
        ));
    }
}

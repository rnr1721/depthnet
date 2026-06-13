<?php

namespace App\Services\Agent\Plugins\Traits;

use App\Services\Agent\Plugins\DTO\PluginExecutionContext;

/**
 * Default routing for plugin methods.
 */
trait PluginMethodTrait
{
    /**
     * Methods on a plugin class that are NOT exposed as commands.
     * Anything not in this list (and not starting with __) is callable
     * via [plugin name]content[/plugin].
     */
    private const EXCLUDED_METHODS = [
        // Identity / metadata
        'getName', 'getDescription', 'getInstructions',
        // Lifecycle / dispatch
        'execute', 'hasMethod', 'callMethod', 'getAvailableMethods',
        // Optional hooks
        'registerShortcodes', 'getToolSchema',
        // UI / formatting
        'getMergeSeparator', 'getSelfClosingTags',
        'getCustomSuccessMessage', 'getCustomErrorMessage',
        'canBeMerged',
        // Config
        'getConfigFields', 'validateConfig', 'getDefaultConfig',
        // Execution metadata side-channel
        'getPluginExecutionMeta',
    ];

    public function hasMethod(string $method): bool
    {

        $camel = $this->kebabToCamel($method);
        return (method_exists($this, $method) || method_exists($this, $camel))
            && !in_array($method, self::EXCLUDED_METHODS, true)
            && !in_array($camel, self::EXCLUDED_METHODS, true);
    }

    public function callMethod(string $method, string $content, PluginExecutionContext $context): string
    {
        $camel = $this->kebabToCamel($method);

        if (method_exists($this, $camel) && !in_array($camel, self::EXCLUDED_METHODS, true)) {
            return $this->{$camel}($content, $context);
        }

        if (method_exists($this, $method) && !in_array($method, self::EXCLUDED_METHODS, true)) {
            return $this->{$method}($content, $context);
        }

        throw new \BadMethodCallException(
            "Method '{$method}' does not exist in " . static::class
        );
    }

    public function getAvailableMethods(): array
    {
        $methods = get_class_methods($this);

        return array_values(array_filter(
            $methods,
            fn ($m) => !in_array($m, self::EXCLUDED_METHODS, true) && !str_starts_with($m, '__')
        ));
    }

    private function kebabToCamel(string $input): string
    {
        return lcfirst(str_replace('-', '', ucwords($input, '-')));
    }

}

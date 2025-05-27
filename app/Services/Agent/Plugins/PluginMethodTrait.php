<?php

namespace App\Services\Agent\Plugins;

trait PluginMethodTrait
{
    public function hasMethod(string $method): bool
    {
        return method_exists($this, $method) && $method !== 'getName' && $method !== 'getDescription';
    }

    public function callMethod(string $method, string $content): string
    {
        if (!$this->hasMethod($method)) {
            throw new \BadMethodCallException("Method '$method' does not exist in " . static::class);
        }

        return $this->$method($content);
    }

    public function getAvailableMethods(): array
    {
        $methods = get_class_methods($this);
        return array_filter($methods, function ($method) {
            return !in_array($method, ['getName', 'getDescription', 'execute', 'hasMethod', 'callMethod', 'getAvailableMethods']);
        });
    }
}

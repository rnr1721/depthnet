<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PluginRegistryInterface;

class PluginRegistry implements PluginRegistryInterface
{
    /**
     * @var CommandPluginInterface[]
     */
    protected array $plugins = [];

    /**
     * @inheritDoc
     */
    public function register(CommandPluginInterface $plugin): self
    {
        $this->plugins[$plugin->getName()] = $plugin;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function has(string $name): bool
    {
        return isset($this->plugins[$name]);
    }

    /**
     * @inheritDoc
     */
    public function get(string $name): ?CommandPluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        return $this->plugins;
    }

    /**
     * @inheritDoc
     */
    public function getAvailablePluginNames(): array
    {
        return array_keys($this->plugins);
    }
}

<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Models\AiPreset;

class PluginRegistry implements PluginRegistryInterface
{
    protected array $disabledForNow = [];

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
        return isset($this->plugins[$name])
            && !in_array($name, $this->disabledForNow)
            && $this->plugins[$name]->isEnabled();
    }

    /**
     * @inheritDoc
     */
    public function get(string $name): ?CommandPluginInterface
    {
        return $this->has($name) ? $this->plugins[$name] : null;
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        $availablePlugins = empty($this->disabledForNow)
            ? $this->plugins
            : array_filter(
                $this->plugins,
                fn ($name) => !in_array($name, $this->disabledForNow),
                ARRAY_FILTER_USE_KEY
            );
        return array_filter(
            $availablePlugins,
            fn ($plugin) => $plugin->isEnabled()
        );
    }

    /**
     * @inheritDoc
     */
    public function allRegistered(): array
    {
        return $this->plugins;
    }

    /**
     * @inheritDoc
     */
    public function getAvailablePluginNames(): array
    {
        return array_keys($this->all());
    }

    /**
     * @inheritDoc
     */
    public function setCurrentPreset(AiPreset $preset): void
    {
        $allPlugins = $this->allRegistered();
        foreach ($allPlugins as $plugin) {
            $plugin->setCurrentPreset($preset);
        }
    }

    /**
     * @inheritDoc
     */
    public function setDisabledForNow(array|string $disabledPlugins): void
    {
        $this->disabledForNow = is_string($disabledPlugins)
            ? array_map('trim', explode(',', $disabledPlugins))
            : $disabledPlugins;
    }
}

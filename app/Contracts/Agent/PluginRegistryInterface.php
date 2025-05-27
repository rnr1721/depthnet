<?php

namespace App\Contracts\Agent;

interface PluginRegistryInterface
{
    /**
     * Register plugin
     *
     * @param CommandPluginInterface $plugin
     * @return self
     */
    public function register(CommandPluginInterface $plugin): self;

    /**
     * Check for plugin availability
     *
     * @param string $name Plugin name
     * @return bool Is plugin available?
     */
    public function has(string $name): bool;

    /**
     * Get plugin by name
     *
     * @param string $name Plugin name
     * @return CommandPluginInterface|null Plugin instance or null if not found
     */
    public function get(string $name): ?CommandPluginInterface;

    /**
     * Get all plugins
     *
     * @return CommandPluginInterface[] List of all plugin instances
     */
    public function all(): array;

    /**
     * Get array with available plugin names
     *
     * @return array Plugin names
     */
    public function getAvailablePluginNames(): array;

}

<?php

namespace App\Services\Agent;

use App\Contracts\Agent\PluginManagerFactoryInterface;
use App\Contracts\Agent\PluginManagerInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Default factory: lazily resolves PluginManagerInterface from the container.
 *
 * The Container itself is safe to inject anywhere — Laravel resolves it
 * specially without going through normal dependency graph traversal. So this
 * factory has no transitive dependencies that could pull in plugins or any
 * other heavyweight services.
 *
 * Caches the resolved instance to avoid repeated container lookups, although
 * since PluginManager is a singleton in the container this is mostly
 * cosmetic — the second container->make() call would return the same
 * instance anyway.
 */
class PluginManagerFactory implements PluginManagerFactoryInterface
{
    private ?PluginManagerInterface $resolved = null;

    public function __construct(
        protected Container $container
    ) {
    }

    public function get(): PluginManagerInterface
    {
        if ($this->resolved === null) {
            $this->resolved = $this->container->make(PluginManagerInterface::class);
        }

        return $this->resolved;
    }
}

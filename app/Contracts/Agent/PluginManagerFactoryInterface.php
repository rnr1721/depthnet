<?php

namespace App\Contracts\Agent;

/**
 * Lazy factory for PluginManagerInterface.
 *
 * Why this exists:
 *   PluginManager has indirect dependencies on plugin instances, and some
 *   plugins (e.g. AgentPlugin) inject PresetServiceInterface in their
 *   constructors. This creates a circular dependency the moment PresetService
 *   tries to inject PluginManager directly:
 *
 *     PresetService → PluginManager → (plugins) → PresetService
 *
 *   The factory breaks the cycle by deferring the actual resolution until
 *   the moment PluginManager is needed (after PresetService is fully built).
 *
 * Usage:
 *   Inject this factory instead of PluginManagerInterface in any service
 *   that participates in the cycle. Call get() right before you need the
 *   manager — by that time the container will have finished resolving
 *   everything else, and the cycle is broken.
 *
 *   public function __construct(protected PluginManagerFactoryInterface $pmf) {}
 *
 *   public function someMethod() {
 *       $this->pmf->get()->initializeConfigsForPreset($preset);
 *   }
 *
 * Implementations may cache the resolved instance internally — PluginManager
 * is a singleton in the container, so repeated calls return the same object.
 */
interface PluginManagerFactoryInterface
{
    /**
     * Resolve and return the PluginManager instance.
     *
     * @return PluginManagerInterface
     */
    public function get(): PluginManagerInterface;
}

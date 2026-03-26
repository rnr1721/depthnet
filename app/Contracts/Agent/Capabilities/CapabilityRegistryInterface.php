<?php

namespace App\Contracts\Agent\Capabilities;

use App\Models\AiPreset;
use App\Models\PresetCapabilityConfig;

/**
 * Registry for capability providers of a specific type.
 *
 * One registry instance exists per capability type (embedding, image, ...).
 * Providers register themselves via a ServiceProvider.
 * The registry creates configured instances from preset DB configs.
 *
 * @template T of CapabilityProviderInterface
 */
interface CapabilityRegistryInterface
{
    /**
     * Register a provider prototype.
     * Called from a ServiceProvider during boot.
     */
    public function register(CapabilityProviderInterface $provider): static;

    /**
     * Check whether a driver is registered.
     */
    public function has(string $driverName): bool;

    /**
     * Get all registered provider prototypes, keyed by driver name.
     * Used by the GUI to list available drivers and their config fields.
     *
     * @return array<string, CapabilityProviderInterface>
     */
    public function all(): array;

    /**
     * Create a configured provider instance for the given preset.
     *
     * Looks up the active capability config for this preset in the DB,
     * finds the matching registered driver, and instantiates it with
     * the stored config values.
     *
     * @param  AiPreset  $preset
     * @return T
     * @throws \RuntimeException if no active config found or driver not registered.
     */
    public function makeForPreset(AiPreset $preset): CapabilityProviderInterface;

    /**
     * Create a configured provider instance from an explicit config record.
     * Useful when the config is already loaded to avoid an extra DB query.
     *
     * @param  PresetCapabilityConfig  $config
     * @return T
     */
    public function makeFromConfig(PresetCapabilityConfig $config): CapabilityProviderInterface;

    /**
     * Check whether a usable (active + registered driver) config exists for the preset.
     */
    public function isAvailableForPreset(AiPreset $preset): bool;
}

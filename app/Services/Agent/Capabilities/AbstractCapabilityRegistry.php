<?php

namespace App\Services\Agent\Capabilities;

use App\Contracts\Agent\Capabilities\CapabilityProviderInterface;
use App\Contracts\Agent\Capabilities\CapabilityRegistryInterface;
use App\Models\AiPreset;
use App\Models\PresetCapabilityConfig;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * Abstract base for all capability registries.
 *
 * Handles provider registration and DB config lookup.
 * Subclasses only need to implement:
 *  - getCapabilityType()  — the capability string ('embedding', 'image', ...)
 *  - instantiate()        — how to construct a provider from driver name + config
 */
abstract class AbstractCapabilityRegistry implements CapabilityRegistryInterface
{
    /** @var array<string, CapabilityProviderInterface> */
    protected array $providers = [];

    public function __construct(
        protected HttpFactory $http,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * The capability type string stored in preset_capability_configs.capability.
     * Implemented by each concrete registry.
     */
    abstract protected function getCapabilityType(): string;

    /**
     * Instantiate a provider for the given driver using the provided config.
     * Implemented by each concrete registry since it knows the concrete classes.
     *
     * @param  string               $driverName
     * @param  array<string, mixed> $config
     * @return CapabilityProviderInterface
     */
    abstract protected function instantiate(string $driverName, array $config): CapabilityProviderInterface;

    // -------------------------------------------------------------------------
    // CapabilityRegistryInterface
    // -------------------------------------------------------------------------

    public function register(CapabilityProviderInterface $provider): static
    {
        $this->providers[$provider->getDriverName()] = $provider;
        return $this;
    }

    public function has(string $driverName): bool
    {
        return isset($this->providers[$driverName]);
    }

    public function all(): array
    {
        return $this->providers;
    }

    public function makeForPreset(AiPreset $preset): CapabilityProviderInterface
    {
        $config = PresetCapabilityConfig::forPreset($preset->id)
            ->forCapability($this->getCapabilityType())
            ->active()
            ->first();

        if ($config === null) {
            throw new \RuntimeException(sprintf(
                "No active %s capability config found for preset #%d (%s).",
                $this->getCapabilityType(),
                $preset->id,
                $preset->getName(),
            ));
        }

        return $this->makeFromConfig($config);
    }

    public function makeFromConfig(PresetCapabilityConfig $config): CapabilityProviderInterface
    {
        $driverName = $config->driver;

        if (!$this->has($driverName)) {
            throw new \RuntimeException(sprintf(
                "Capability driver '%s' is not registered. Available: [%s].",
                $driverName,
                implode(', ', array_keys($this->providers)),
            ));
        }

        return $this->instantiate($driverName, $config->config ?? []);
    }

    public function isAvailableForPreset(AiPreset $preset): bool
    {
        $config = PresetCapabilityConfig::forPreset($preset->id)
            ->forCapability($this->getCapabilityType())
            ->active()
            ->first();

        return $config !== null && $this->has($config->driver);
    }
}

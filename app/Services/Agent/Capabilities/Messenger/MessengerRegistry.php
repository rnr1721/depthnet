<?php

namespace App\Services\Agent\Capabilities\Messenger;

use App\Contracts\Agent\Capabilities\CapabilityProviderInterface;
use App\Contracts\Agent\Capabilities\MessengerProviderInterface;
use App\Models\PresetCapabilityConfig;
use App\Services\Agent\Capabilities\AbstractCapabilityRegistry;
use App\Services\Agent\Capabilities\Messenger\Drivers\Telegram\TelegramMessengerProvider;
use App\Services\Agent\Capabilities\Messenger\Drivers\Telegram\TgcliRunner;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * Registry for messenger capability providers.
 *
 * Differs from the other registries in one way: a messenger provider owns an
 * account session that belongs to one preset, so it must know which preset it
 * serves. makeFromConfig() therefore passes the config's preset_id through to
 * the driver. instantiate() (used for prototypes — config fields, defaults,
 * validation) builds an unbound provider that refuses real operations.
 */
class MessengerRegistry extends AbstractCapabilityRegistry
{
    public function __construct(
        HttpFactory $http,
        LoggerInterface $logger,
        protected TgcliRunner $tgcli,
        protected string $telegramDataDir,
    ) {
        parent::__construct($http, $logger);
    }

    protected function getCapabilityType(): string
    {
        return MessengerProviderInterface::CAPABILITY;
    }

    public function makeFromConfig(PresetCapabilityConfig $config): CapabilityProviderInterface
    {
        $driverName = $config->driver;

        if (!$this->has($driverName)) {
            throw new \RuntimeException(sprintf(
                "Messenger driver '%s' is not registered. Available: [%s].",
                $driverName,
                implode(', ', array_keys($this->providers)),
            ));
        }

        return $this->build($driverName, $config->config ?? [], (int) $config->preset_id);
    }

    protected function instantiate(string $driverName, array $config): CapabilityProviderInterface
    {
        return $this->build($driverName, $config, null);
    }

    /**
     * Add a match arm when adding a new messenger driver.
     */
    protected function build(string $driverName, array $config, ?int $presetId): MessengerProviderInterface
    {
        return match ($driverName) {
            'telegram' => new TelegramMessengerProvider(
                $this->tgcli,
                $this->logger,
                $this->telegramDataDir,
                $config,
                $presetId,
            ),
            default => throw new \InvalidArgumentException(
                "No instantiation logic for messenger driver '{$driverName}'."
            ),
        };
    }
}

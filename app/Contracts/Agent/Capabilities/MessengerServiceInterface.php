<?php

namespace App\Contracts\Agent\Capabilities;

use App\Models\AiPreset;
use App\Services\Agent\Capabilities\Messenger\DTO\InboundSettings;

/**
 * High-level messenger service: resolves the preset's provider and its
 * inbound policy. Consumers (MessengerPlugin, the inbound poller, the
 * capability controller) go through here rather than the registry.
 */
interface MessengerServiceInterface
{
    /**
     * The preset's configured, active provider — or null when messenger is not
     * configured, inactive, or its driver is not registered.
     */
    public function provider(AiPreset $preset): ?MessengerProviderInterface;

    /**
     * Whether a usable (active + registered driver) messenger config exists.
     */
    public function isAvailable(AiPreset $preset): bool;

    /**
     * Inbound policy for the preset. Returns InboundSettings::off() when the
     * capability is not configured or not active, so callers never need to
     * null-check before asking "should this message get in?".
     */
    public function inboundSettings(AiPreset $preset): InboundSettings;
}

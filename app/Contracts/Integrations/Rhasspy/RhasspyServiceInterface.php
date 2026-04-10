<?php

namespace App\Contracts\Integrations\Rhasspy;

use App\Models\AiPreset;

/**
 * Business logic layer for Rhasspy integration.
 * Knows about presets, checks if integration is enabled, delegates to client.
 */
interface RhasspyServiceInterface
{
    /**
     * Speak a text via Rhasspy if integration is enabled for the given preset.
     * Never throws — all errors are logged and swallowed.
     *
     * @param string   $text   Text to speak
     * @param AiPreset $preset Preset whose Rhasspy config to use
     * @return void
     */
    public function speakForPreset(string $text, AiPreset $preset): void;

    /**
     * Check if Rhasspy integration is fully configured and enabled for a preset.
     *
     * @param AiPreset $preset
     * @return bool
     */
    public function isEnabledForPreset(AiPreset $preset): bool;

    /**
     * Validate incoming webhook token for a preset.
     *
     * @param AiPreset $preset
     * @param string   $token
     * @return bool
     */
    public function validateIncomingToken(AiPreset $preset, string $token): bool;

    /**
     * Check if incoming webhook is enabled for a preset.
     *
     * @param AiPreset $preset
     * @return bool
     */
    public function isIncomingEnabledForPreset(AiPreset $preset): bool;

    /**
     * Create a client instance configured for the given preset.
     * Useful for health checks from admin UI.
     *
     * @param AiPreset $preset
     * @return RhasspyClientInterface
     */
    public function makeClientForPreset(AiPreset $preset): RhasspyClientInterface;
}

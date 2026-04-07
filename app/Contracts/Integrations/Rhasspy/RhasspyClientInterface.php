<?php

namespace App\Contracts\Integrations\Rhasspy;

/**
 * Low-level HTTP client for Rhasspy API.
 * Knows nothing about presets or business logic — just speaks HTTP.
 */
interface RhasspyClientInterface
{
    /**
     * Send text to Rhasspy TTS endpoint and play it.
     *
     * @param string $text  Text to speak
     * @param string $voice Optional voice name (empty string = server default)
     * @return bool         True if Rhasspy accepted the request
     */
    public function say(string $text, string $voice = ''): bool;

    /**
     * Ping Rhasspy to check availability.
     *
     * @return bool True if Rhasspy is reachable
     */
    public function isAvailable(): bool;
}

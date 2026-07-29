<?php

namespace App\Contracts\Agent\Capabilities;

use App\Models\AiPreset;
use App\Services\Agent\Capabilities\Speech\DTO\TtsResult;

/**
 * High-level text-to-speech service.
 *
 * Shares the client/server distinction with SttServiceInterface: a preset may
 * have TTS active while being unable to synthesize on the backend, because the
 * browser provider speaks client-side.
 */
interface TtsServiceInterface
{
    /**
     * Synthesize speech. Text is cleaned via prepareText() first.
     */
    public function synthesizeResult(
        string $text,
        AiPreset $preset,
        ?string $voice = null,
        float $speed = 1.0,
    ): TtsResult;

    /**
     * Whether a usable TTS config exists (client-side included).
     */
    public function isAvailable(AiPreset $preset): bool;

    /**
     * Whether synthesis can happen on the backend.
     */
    public function isServerSideAvailable(AiPreset $preset): bool;

    /**
     * Secret-free config for the frontend, plus execution mode.
     *
     * @return array<string, mixed>|null
     */
    public function getClientConfig(AiPreset $preset): ?array;

    /**
     * Strip markup, command tags and tool output from agent text so it can be
     * spoken.
     *
     * This is the PHP counterpart of cleanTextForSpeech() in useVoice.js. Both
     * exist deliberately: the browser provider cleans client-side because it
     * never round-trips through the backend, and non-browser channels clean
     * here. The rules are kept in sync, and this one is authoritative.
     */
    public function prepareText(string $raw): string;
}

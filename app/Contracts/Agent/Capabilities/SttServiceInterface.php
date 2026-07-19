<?php

namespace App\Contracts\Agent\Capabilities;

use App\Models\AiPreset;
use App\Services\Agent\Capabilities\Speech\DTO\AudioData;
use App\Services\Agent\Capabilities\Speech\DTO\SttResult;

/**
 * High-level speech-to-text service.
 *
 * transcribeResult() — structured, lets callers surface why STT failed.
 * transcribe()       — ?string facade over it.
 *
 * The client/server split shows up here as isServerSideAvailable(): a preset can
 * have STT configured and active while still being unable to transcribe on the
 * backend, because its provider runs in the browser. A Telegram voice message
 * arriving for such a preset is a configuration problem, not a runtime error,
 * and callers should be able to tell the difference.
 */
interface SttServiceInterface
{
    /**
     * Structured transcription — carries a human-readable reason on failure.
     *
     * When $language is null the preset's configured language is used, falling
     * back to provider autodetect. Explicit beats autodetect on short
     * utterances, where "да" / "da" / "ja" are acoustically ambiguous.
     */
    public function transcribeResult(
        AudioData $audio,
        AiPreset $preset,
        ?string $language = null,
    ): SttResult;

    /**
     * Facade: text, or null on any failure.
     */
    public function transcribe(
        AudioData $audio,
        AiPreset $preset,
        ?string $language = null,
    ): ?string;

    /**
     * Whether a usable (active + registered driver) STT config exists.
     * True even for a browser provider that cannot run on the backend.
     */
    public function isAvailable(AiPreset $preset): bool;

    /**
     * Whether the configured provider can actually transcribe on the backend.
     * False for client-side providers. Check this before accepting audio from a
     * non-browser channel.
     */
    public function isServerSideAvailable(AiPreset $preset): bool;

    /**
     * Secret-free config for the frontend, plus execution mode. Null when STT
     * is not configured for the preset.
     *
     * @return array<string, mixed>|null
     */
    public function getClientConfig(AiPreset $preset): ?array;

    /**
     * Whether transcribed speech should be routed into the input pool rather
     * than posted as a plain user message. Mirrors VisionService::shouldSendToPool().
     */
    public function shouldSendToPool(AiPreset $preset): bool;
}

<?php

namespace App\Contracts\Agent\Capabilities;

use App\Services\Agent\Capabilities\Speech\DTO\TtsResult;

/**
 * Optional interface for TTS providers that can synthesize on the backend.
 *
 * Implemented by server-side drivers only. Detected via instanceof.
 *
 * This is what lets an agent speak into a channel that has no browser —
 * a Telegram voice reply, an audio file attached to a message.
 */
interface SynthesizesSpeechInterface
{
    /**
     * Synthesize speech from text.
     *
     * Implementations MUST NOT throw for ordinary API failures — return
     * TtsResult::fail() with a human-readable reason instead.
     *
     * @param  string      $text   Plain text. Callers are expected to have
     *                             stripped markup already (see
     *                             TtsService::prepareText()).
     * @param  string|null $voice  Provider-specific voice id. Null → the
     *                             provider's configured default.
     * @param  float       $speed  1.0 is normal. Providers clamp to their own
     *                             supported range.
     */
    public function synthesize(
        string $text,
        ?string $voice = null,
        float $speed = 1.0,
    ): TtsResult;
}

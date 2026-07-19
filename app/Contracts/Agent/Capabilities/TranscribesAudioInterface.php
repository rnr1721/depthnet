<?php

namespace App\Contracts\Agent\Capabilities;

use App\Services\Agent\Capabilities\Speech\DTO\AudioData;
use App\Services\Agent\Capabilities\Speech\DTO\SttResult;

/**
 * Optional interface for STT providers that can transcribe on the backend.
 *
 * Implemented by every server-side driver and by none of the client-side ones.
 * Callers detect support with instanceof rather than asking the provider —
 * same approach as ListsModelsInterface.
 *
 * This is what makes voice work outside the browser: a Telegram voice message
 * or an uploaded file goes through here without any web UI involved.
 */
interface TranscribesAudioInterface
{
    /**
     * Transcribe audio to text.
     *
     * Implementations MUST NOT throw for ordinary API failures (bad key, model
     * missing, timeout) — they return SttResult::fail() with a concise,
     * human-facing reason. Only genuinely unexpected errors may surface as
     * throws, which SttService catches.
     *
     * @param  AudioData   $audio
     * @param  string|null $language ISO code ('ru', 'en'). Null → provider
     *                               autodetect, which is unreliable on short
     *                               utterances — pass the preset language when
     *                               it is known.
     * @param  string|null $prompt   Optional biasing hint (names, jargon) for
     *                               providers that support it; ignored otherwise.
     */
    public function transcribe(
        AudioData $audio,
        ?string $language = null,
        ?string $prompt = null,
    ): SttResult;
}

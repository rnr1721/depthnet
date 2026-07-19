<?php

namespace App\Services\Agent\Capabilities\Speech\DTO;

/**
 * Result of a speech synthesis attempt.
 *
 * On success carries the audio as AudioData; on failure a human-readable
 * reason. Same contract as VisionResult / SttResult.
 *
 * Note the asymmetry with the browser TTS provider: it produces no audio at all
 * (the client speaks via the Web Speech API), so it never returns a TtsResult —
 * it simply does not implement SynthesizesSpeechInterface. See that interface
 * for the reasoning.
 */
final class TtsResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?AudioData $audio,
        public readonly ?string $error,
    ) {
    }

    public static function ok(AudioData $audio): self
    {
        return new self(true, $audio, null);
    }

    public static function fail(string $error): self
    {
        return new self(false, null, $error);
    }

    public function audioOrNull(): ?AudioData
    {
        return $this->success ? $this->audio : null;
    }
}

<?php

namespace App\Services\Agent\Capabilities\Speech\DTO;

/**
 * Result of a transcription attempt.
 *
 * Same shape as VisionResult: either text or a human-readable reason, so a
 * caller can tell the user WHY speech recognition failed instead of silently
 * dropping their voice message.
 *
 * Error reasons are shown to humans — "Whisper API error (404): model not
 * found", not a stack trace.
 */
final class SttResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $text,
        public readonly ?string $error,
        /** Detected or requested language code ('ru', 'en'), when known. */
        public readonly ?string $language = null,
        /** Audio duration in seconds as reported by the provider, when known. */
        public readonly ?float $durationSec = null,
    ) {
    }

    public static function ok(
        string $text,
        ?string $language = null,
        ?float $durationSec = null,
    ): self {
        return new self(true, $text, null, $language, $durationSec);
    }

    public static function fail(string $error): self
    {
        return new self(false, null, $error);
    }

    /** Convenience for the legacy ?string facade. */
    public function textOrNull(): ?string
    {
        return $this->success ? $this->text : null;
    }

    /**
     * Whisper on silence or noise happily returns an empty string, or a
     * hallucinated stock phrase. An empty transcription is not an error, but it
     * is not usable either — callers check this before routing to the agent.
     */
    public function isEmpty(): bool
    {
        return !$this->success || trim((string) $this->text) === '';
    }
}

<?php

namespace App\Services\Agent\Capabilities\Vision\DTO;

/**
 * Result of a vision describe attempt.
 *
 * Carries either a description (success) or a human-readable error reason
 * (failure), so callers can surface WHY vision failed instead of just getting
 * a silent null. The legacy describe(): ?string facade is built on top of this.
 *
 * Error reasons are meant to be shown to a human (file meta, tool result),
 * so they should be concise and actionable — e.g. "Novita API error (401):
 * invalid api key" rather than a raw stack trace.
 */
final class VisionResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $text,
        public readonly ?string $error,
    ) {
    }

    public static function ok(string $text): self
    {
        return new self(true, $text, null);
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
}

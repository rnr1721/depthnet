<?php

namespace App\Services\Agent\Browser\DTO;

/**
 * Result of a single browser operation.
 *
 * Follows the same ok/fail shape as VisionResult so the codebase has one
 * consistent result idiom. A result may carry:
 *   - a snapshot   (open / snapshot / click / press / back / scroll / type+submit)
 *   - an action confirmation line ("Clicked: 3", "Typed ... (submitted)")
 *   - an error     (fail branch)
 *
 * The plugin decides how to render these into agent-facing text.
 */
final class BrowserResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?BrowserSnapshot $snapshot = null,
        public readonly ?string $confirmation = null,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * Success carrying a page snapshot, optionally preceded by an action
     * confirmation line (e.g. the click that produced this page).
     */
    public static function withSnapshot(BrowserSnapshot $snapshot, ?string $confirmation = null): self
    {
        return new self(ok: true, snapshot: $snapshot, confirmation: $confirmation);
    }

    /**
     * Success carrying only a confirmation line (no snapshot) —
     * e.g. type without submit, session close, ping.
     */
    public static function confirmed(string $confirmation): self
    {
        return new self(ok: true, confirmation: $confirmation);
    }

    /**
     * Failure. $error is a human-readable reason surfaced to the agent.
     */
    public static function fail(string $error): self
    {
        return new self(ok: false, error: $error);
    }

    public function hasSnapshot(): bool
    {
        return $this->snapshot !== null;
    }
}

<?php

namespace App\Contracts\Agent\Browser;

use App\Services\Agent\Browser\DTO\BrowserResult;

/**
 * Transport layer to the Playwright browser-service.
 *
 * Knows nothing about presets, plugins, or domain policy. It speaks HTTP to
 * the node service, maps responses into BrowserResult DTOs, and turns
 * transport failures into fail() results rather than throwing. The session is
 * identified by an opaque string the caller chooses (the plugin uses
 * "preset_<id>"), so sessions persist across thinking cycles.
 */
interface BrowserServiceInterface
{
    /**
     * Navigate to a URL. Returns a snapshot of the loaded page.
     */
    public function open(string $sessionId, string $url): BrowserResult;

    /**
     * Snapshot the current page without navigating.
     */
    public function snapshot(string $sessionId): BrowserResult;

    /**
     * Click an element by numbered handle, CSS selector, or visible text.
     * Returns a snapshot of the resulting page state.
     */
    public function click(string $sessionId, string $target): BrowserResult;

    /**
     * Type text into a field (by handle/selector/text). When $submit is true,
     * presses Enter afterwards and returns a snapshot; otherwise returns a
     * plain confirmation.
     */
    public function type(string $sessionId, string $target, string $text, bool $submit = false): BrowserResult;

    /**
     * Press a keyboard key (Enter, Tab, Escape, ...). Returns a snapshot.
     */
    public function press(string $sessionId, string $key): BrowserResult;

    /**
     * Scroll the page by a pixel amount. Returns a snapshot.
     */
    public function scroll(string $sessionId, int $pixels): BrowserResult;

    /**
     * Go back in history. Returns a snapshot.
     */
    public function back(string $sessionId): BrowserResult;

    /**
     * Close and free the session's browser.
     */
    public function close(string $sessionId): BrowserResult;

    /**
     * Liveness check against the service.
     */
    public function ping(): bool;
}

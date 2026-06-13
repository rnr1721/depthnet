<?php

namespace App\Services\Agent\Browser\DTO;

/**
 * Typed snapshot of a page's state, as perceived by the agent.
 *
 * Built from the browser-service JSON payload. Keeps the structured shape
 * (typed element collections, scroll geometry) so the plugin can render it
 * however it likes without poking at loose array keys.
 */
final class BrowserSnapshot
{
    /**
     * @param BrowserElement[] $links
     * @param BrowserElement[] $inputs
     * @param BrowserElement[] $buttons
     */
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly string $text,
        public readonly bool $textTruncated,
        public readonly bool $modalOpen,
        public readonly array $links,
        public readonly array $inputs,
        public readonly array $buttons,
        public readonly int $scrollY,
        public readonly int $scrollHeight,
        public readonly int $viewportHeight,
    ) {
    }

    /**
     * Construct from the raw browser-service payload.
     *
     * @param array $data Decoded JSON from POST /action
     */
    public static function fromPayload(array $data): self
    {
        return new self(
            title:          (string) ($data['title'] ?? '[no title]'),
            url:            (string) ($data['url'] ?? '[unknown url]'),
            text:           (string) ($data['text'] ?? ''),
            textTruncated:  (bool) ($data['textTruncated'] ?? false),
            modalOpen:      (bool) ($data['modalOpen'] ?? false),
            links:          array_map([BrowserElement::class, 'link'], $data['links'] ?? []),
            inputs:         array_map([BrowserElement::class, 'input'], $data['inputs'] ?? []),
            buttons:        array_map([BrowserElement::class, 'button'], $data['buttons'] ?? []),
            scrollY:        (int) ($data['scrollY'] ?? 0),
            scrollHeight:   (int) ($data['scrollHeight'] ?? 0),
            viewportHeight: (int) ($data['viewportHeight'] ?? 0),
        );
    }

    /**
     * True when the page extends meaningfully below the current viewport.
     */
    public function hasMoreBelow(): bool
    {
        return $this->scrollHeight > ($this->scrollY + $this->viewportHeight + 10);
    }

    /**
     * Scroll progress as a 0-100 percentage of the page consumed so far.
     */
    public function scrollPercent(): int
    {
        if ($this->scrollHeight <= 0) {
            return 100;
        }
        $bottom = $this->scrollY + $this->viewportHeight;
        return (int) round(min(1.0, $bottom / $this->scrollHeight) * 100);
    }

    /**
     * Whether the page is tall enough for scroll position to be worth showing.
     */
    public function isScrollable(): bool
    {
        return $this->scrollHeight > ($this->viewportHeight + 50);
    }
}

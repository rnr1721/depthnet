<?php

namespace App\Services\Agent\Browser\DTO;

/**
 * A single interactive element exposed to the agent in a snapshot.
 *
 * Every element carries a numbered handle (`ref`) that the browser-service
 * stamped into the live DOM as `data-dn-ref="N"`. The agent acts on elements
 * by that number — [browser click]3[/browser] — and the service resolves the
 * number back to the exact element. This is far more reliable than CSS
 * selectors guessed from the page and far easier for the model to get right.
 */
final class BrowserElement
{
    public function __construct(
        public readonly string $ref,
        public readonly string $label,
        public readonly ?string $url = null,         // links only
        public readonly ?string $type = null,        // inputs only (email/password/...)
        public readonly ?string $placeholder = null, // inputs only
        public readonly ?string $value = null,        // inputs only — what's currently entered
    ) {
    }

    /**
     * Build a link element from a raw service payload row.
     */
    public static function link(array $row): self
    {
        return new self(
            ref:   (string) ($row['ref'] ?? ''),
            label: (string) ($row['text'] ?? ''),
            url:   $row['url'] ?? null,
        );
    }

    /**
     * Build an input element from a raw service payload row.
     */
    public static function input(array $row): self
    {
        return new self(
            ref:         (string) ($row['ref'] ?? ''),
            label:       (string) ($row['name'] ?? ''),
            type:        $row['type'] ?? 'text',
            placeholder: $row['placeholder'] ?? null,
            value:       ($row['value'] ?? '') !== '' ? (string) $row['value'] : null,
        );
    }

    /**
     * Build a button element from a raw service payload row.
     */
    public static function button(array $row): self
    {
        return new self(
            ref:   (string) ($row['ref'] ?? ''),
            label: (string) ($row['text'] ?? ''),
        );
    }
}

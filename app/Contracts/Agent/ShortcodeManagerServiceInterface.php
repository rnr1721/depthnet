<?php

namespace App\Contracts\Agent;

interface ShortcodeManagerServiceInterface
{
    /**
     * Set all default shortcodes for AI context
     *
     * @return void
     */
    public function setDefaultShortcodes(): void;

    /**
     * Register a custom shortcode
     *
     * @param string $key
     * @param string $description
     * @param callable $callback
     * @return void
     */
    public function registerShortcode(
        string $key,
        string $description,
        callable $callback
    ): void;

    /**
     * Remove a registered shortcode
     *
     * @param string $key
     * @return bool
     */
    public function unregisterShortcode(string $key): bool;

    /**
     * Get all registered shortcodes
     *
     * @return array
     */
    public function getRegisteredShortcodes(): array;

    /**
     * Check if a shortcode is registered
     *
     * @param string $key
     * @return bool
     */
    public function hasShortcode(string $key): bool;

    /**
     * Process shortcodes in a given text
     *
     * @param string $text
     * @return string
     */
    public function processShortcodes(string $text): string;

    /**
     * Get shortcode value by key
     *
     * @param string $key
     * @return string|null
     */
    public function getShortcodeValue(string $key): ?string;
}

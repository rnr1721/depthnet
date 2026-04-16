<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;

interface ShortcodeManagerServiceInterface
{
    /**
     * Set all default shortcodes for AI context (registered globally)
     *
     * @param AiPreset $preset Preset for which to set up shortcodes (some shortcodes may be conditional on preset settings)
     * @return void
     */
    public function setDefaultShortcodes(AiPreset $preset): void;

    /**
     * Register a custom shortcode in the global scope
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
     * Register a shortcode scoped to a specific preset.
     * Preset-scoped shortcodes override global ones with the same key
     * when processing content for that preset.
     *
     * @param int $presetId
     * @param string $key
     * @param string $description
     * @param callable $callback
     * @return void
     */
    public function registerShortcodeForPreset(
        int $presetId,
        string $key,
        string $description,
        callable $callback
    ): void;

    /**
     * Remove a registered shortcode
     *
     * @param string $key
     * @param int|null $presetId Preset to remove from, null = global
     * @return bool
     */
    public function unregisterShortcode(string $key, ?int $presetId = null): bool;

    /**
     * Get all registered shortcodes.
     * If presetId is given, returns global merged with preset-specific
     * (preset overrides global for same key).
     *
     * @param int|null $presetId
     * @return array
     */
    public function getRegisteredShortcodes(?int $presetId = null): array;

    /**
     * Check if a shortcode is registered
     *
     * @param string $key
     * @param int|null $presetId Check in preset scope as well
     * @return bool
     */
    public function hasShortcode(string $key, ?int $presetId = null): bool;

    /**
     * Process shortcodes in a given text.
     * If presetId is given, preset-scoped shortcodes override global ones.
     *
     * @param string $text
     * @param int|null $presetId
     * @return string
     */
    public function processShortcodes(string $text, ?int $presetId = null): string;

    /**
     * Get shortcode value by key
     *
     * @param string $key
     * @param int|null $presetId
     * @return string|null
     */
    public function getShortcodeValue(string $key, ?int $presetId = null): ?string;

    /**
     * Clear all shortcodes for a specific preset scope.
     * Does not affect global shortcodes.
     *
     * @param int $presetId
     * @return void
     */
    public function clearPresetShortcodes(int $presetId): void;
}

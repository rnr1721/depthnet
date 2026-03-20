<?php

namespace App\Contracts\Chat;

use App\Models\AiPreset;
use App\Models\PresetKnownSource;
use Illuminate\Database\Eloquent\Collection;

interface InputPoolServiceInterface
{
    /**
     * Whether pool input mode is enabled for the given preset.
     *
     * @param AiPreset $preset
     * @return boolean
     */
    public function isEnabled(AiPreset $preset): bool;

    /**
     * Add or overwrite a source in the pool.
     * If a source with the same name already exists for this preset — it gets overwritten.
     *
     * @param integer $presetId
     * @param string $sourceName
     * @param string $content
     * @return void
     */
    public function add(int $presetId, string $sourceName, string $content): void;

    /**
     * Build JSON from regular (non-known) pool items without clearing the pool.
     * Known sources are excluded from the payload — they belong in the system prompt
     * via [[known_sources]], not in the user message.
     *
     * Returns null if the pool is empty or contains only known sources.
     *
     * Result format:
     * {
     *   "sources": [
     *     {"source": "message_from_user John:", "content": "...", "timestamp": "..."},
     *     {"source": "webhook_weather",          "content": "...", "timestamp": "..."}
     *   ]
     * }
     *
     * @param integer $presetId
     * @return string|null
     */
    public function getAllAsJSON(int $presetId): ?string;

    /**
     * Build JSON from regular pool items and then clear the entire pool.
     * Equivalent to getAllAsJSON() followed by clear().
     * Use when dispatching the pool as a user message.
     *
     * @param int $presetId Preset Id
     * @return string|null Null if the pool was empty before clearing
     */
    public function flush(int $presetId): ?string;

    /**
     * Delete all pool items for a preset without returning anything.
     *
     * @param integer $presetId
     * @return void
     */
    public function clear(int $presetId): void;

    /**
     * Get all current pool items for a preset (useful for UI monitoring).
     *
     * @param integer $presetId
     * @return Collection
     */
    public function getItems(int $presetId): Collection;

    // -------------------------------------------------------------------------
    // Known sources
    // -------------------------------------------------------------------------

    /**
     * Return a formatted text block representing the current state of all
     * defined known sources for this preset. Intended for injection into
     * the system prompt via the [[known_sources]] shortcode.
     *
     * Registration of the shortcode itself is the responsibility of the
     * context builder (CycleContextBuilder / SingleContextBuilder), not this service.
     *
     * For each defined known source:
     *   - Pool item with matching source_name exists → show its current value + timestamp
     *   - No pool item but default_value is set     → show the default (marked as "default")
     *   - Neither                                   → skip silently
     *
     * Output example:
     *   [sensor_temp] 36.6°C (2025-01-01T12:00:00+00:00)
     *   [heartrate] unknown (default)
     *
     * Returns null if no known sources are defined or nothing to show.
     *
     * @param integer $presetId
     * @return string|null
     */
    public function getKnownSourcesBlock(int $presetId): ?string;

    /**
     * Get all known source definitions for a preset, ordered by sort_order.
     *
     * @param integer $presetId
     * @return Collection<PresetKnownSource>
     */
    public function getKnownSources(int $presetId): Collection;

    /**
     * Add or update a known source definition for a preset.
     *
     * Known sources are excluded from the regular JSON payload and instead
     * surface in the system prompt via [[known_sources]]. This lets the model
     * treat them as part of its own context — body projection, ambient signals,
     * sensor state — rather than as incoming external messages.
     *
     * @param string      $sourceName   Exact match against pool item source_name
     * @param string      $label        Human-readable name shown in UI
     * @param string|null $description  Optional hint shown in the shortcode block
     * @param string|null $defaultValue Value shown when the source sends no data
     */
    public function addKnownSource(
        int $presetId,
        string $sourceName,
        string $label,
        ?string $description = null,
        ?string $defaultValue = null,
    ): PresetKnownSource;

    /**
     * Remove a known source definition by its source name.
     *
     * @param integer $presetId
     * @param string $sourceName
     * @return void
     */
    public function removeKnownSource(int $presetId, string $sourceName): void;

    /**
     * Reorder known sources for a preset.
     * Accepts an ordered array of IDs — position in the array becomes sort_order.
     * The order is reflected in the [[known_sources]] shortcode output.
     *
     * @param int $presetId
     * @param int[] $orderedIds IDs in desired display/injection order
     */
    public function reorderKnownSources(int $presetId, array $orderedIds): void;
}

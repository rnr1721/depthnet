<?php

namespace App\Contracts\Agent\Models;

use App\Models\AiPreset;
use Illuminate\Support\Collection;

/**
 * Interface for AI preset registry with caching and validation
 *
 * Provides centralized access to AI presets with intelligent caching,
 * fallback mechanisms, validation, and integration with the engine registry.
 */
interface PresetRegistryInterface
{
    /**
     * Get all active presets
     *
     * @return Collection<AiPreset> Collection of active presets ordered by name
     */
    public function getActivePresets(): Collection;

    /**
     * Get a specific preset by ID
     *
     * @param int $id Preset ID
     * @return AiPreset The preset
     * @throws \App\Exceptions\PresetNotFoundException When preset not found
     * @throws \App\Exceptions\PresetNotActiveException When preset is not active
     */
    public function getPreset(int $id): AiPreset;

    /**
     * Get the default preset
     *
     * @return AiPreset The default preset
     * @throws \App\Exceptions\NoActivePresetsException When no active presets found
     */
    public function getDefaultPreset(): AiPreset;

    /**
     * Create an AI engine instance from preset
     *
     * @param int $presetId Preset ID
     * @return AIModelEngineInterface Configured engine instance
     * @throws \App\Exceptions\PresetNotFoundException When preset not found
     * @throws \App\Exceptions\PresetNotActiveException When preset is not active
     * @throws \Exception When engine is not available or disabled
     */
    public function createInstance(int $presetId): AIModelEngineInterface;

    /**
     * Refresh all caches
     *
     * @return void
     */
    public function refresh(): void;

    /**
     * Get presets filtered by engine name
     *
     * @param string $engineName Engine name to filter by
     * @return Collection<AiPreset> Active presets for the specified engine
     */
    public function getPresetsByEngine(string $engineName): Collection;

    /**
     * Get all presets including inactive ones (admin purposes)
     *
     * @return Collection<AiPreset> All presets ordered by name
     */
    public function getAllPresets(): Collection;

    /**
     * Find preset by name (active only)
     *
     * @param string $name Preset name
     * @return AiPreset|null The preset or null if not found
     */
    public function findByName(string $name): ?AiPreset;

    /**
     * Get preset with fallback to default
     *
     * @param int|null $presetId Preset ID or null for default
     * @return AiPreset The requested preset or default if not found/inactive
     */
    public function getPresetOrDefault(?int $presetId = null): AiPreset;

    /**
     * Create engine instance with fallback to default preset
     *
     * @param int|null $presetId Preset ID or null for default
     * @return AIModelEngineInterface Configured engine instance
     * @throws \App\Exceptions\NoActivePresetsException When no active presets found
     */
    public function createInstanceOrDefault(?int $presetId = null): AIModelEngineInterface;

    /**
     * Validate preset configuration
     *
     * @param int $presetId Preset ID
     * @return array Validation errors (empty array if valid)
     * @throws \App\Exceptions\PresetNotFoundException When preset not found
     * @throws \App\Exceptions\PresetNotActiveException When preset is not active
     */
    public function validatePreset(int $presetId): array;

    /**
     * Test preset connection to AI service
     *
     * @param int $presetId Preset ID
     * @return array Test results with success status, response time, and error info
     */
    public function testPresetConnection(int $presetId): array;

    /**
     * Get comprehensive preset statistics
     *
     * @return array Statistics including totals, engine distribution, and availability
     */
    public function getPresetStats(): array;

    /**
     * Refresh cache for specific preset
     *
     * @param int $presetId Preset ID
     * @return void
     */
    public function refreshPreset(int $presetId): void;

    /**
     * Check if preset is usable (exists, active, and engine available)
     *
     * @param int $presetId Preset ID
     * @return bool True if preset is usable
     */
    public function isPresetUsable(int $presetId): bool;

    /**
     * Get list of usable presets (active + engine available)
     *
     * @return Collection<AiPreset> Usable presets
     */
    public function getUsablePresets(): Collection;

    /**
     * Auto-repair presets by disabling those with unavailable engines
     *
     * @return array List of repaired presets with details
     */
    public function autoRepairPresets(): array;

    /**
     * Warm up the cache by preloading commonly used data
     *
     * @return void
     */
    public function warmCache(): void;
}

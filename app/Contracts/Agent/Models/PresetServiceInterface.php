<?php

namespace App\Contracts\Agent\Models;

use App\Models\AiPreset;
use Illuminate\Support\Collection;

/**
 * Interface for managing AI presets with enhanced validation and logging
 */
interface PresetServiceInterface
{
    /**
     * Create a new preset
     *
     * @param array $data Preset data including name, description, engine_name, engine_config, etc.
     * @return AiPreset The created preset
     * @throws \Illuminate\Validation\ValidationException When validation fails
     * @throws \Exception When engine doesn't exist or config is invalid
     */
    public function createPreset(array $data): AiPreset;

    /**
     * Update an existing preset
     *
     * @param int $id Preset ID
     * @param array $data Updated data
     * @return AiPreset The updated preset
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When preset not found
     * @throws \Illuminate\Validation\ValidationException When validation fails
     * @throws \Exception When engine doesn't exist or config is invalid
     */
    public function updatePreset(int $id, array $data): AiPreset;

    /**
     * Delete a preset
     *
     * @param int $id Preset ID
     * @return bool True if deleted successfully
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When preset not found
     * @throws \Exception When trying to delete default preset
     */
    public function deletePreset(int $id): bool;

    /**
     * Create preset with enhanced validation and logging
     *
     * @param array $data Preset data
     * @return AiPreset The created preset
     * @throws \App\Exceptions\PresetException When validation fails
     */
    public function createPresetWithValidation(array $data): AiPreset;

    /**
     * Update preset with enhanced validation and logging
     *
     * @param int $id Preset ID
     * @param array $data Updated data
     * @return AiPreset The updated preset
     * @throws \App\Exceptions\PresetException When validation fails
     */
    public function updatePresetWithValidation(int $id, array $data): AiPreset;

    /**
     * Delete preset with enhanced validation and logging
     *
     * @param int $id Preset ID
     * @return void
     * @throws \App\Exceptions\PresetException When trying to delete default preset
     */
    public function deletePresetWithValidation(int $id): void;

    /**
     * Set default preset with enhanced logging
     *
     * @param int $id Preset ID
     * @return void
     * @throws \App\Exceptions\PresetException When preset not found
     */
    public function setDefaultPresetWithLogging(int $id): void;

    /**
     * Find preset by ID
     *
     * @param int $id Preset ID
     * @return AiPreset|null The preset or null if not found
     */
    public function findById(int $id): ?AiPreset;

    /**
     * Find preset by ID or fail
     *
     * @param int $id Preset ID
     * @return AiPreset The preset
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When preset not found
     */
    public function findByIdOrFail(int $id): AiPreset;

    /**
     * Get the current default preset
     *
     * @return AiPreset|null The default preset or null if none set
     */
    public function getDefaultPreset(): ?AiPreset;

    /**
     * Get the default preset or first active preset as fallback
     *
     * @return AiPreset|null The default preset or first active preset
     */
    public function getDefaultOrFirstActivePreset(): ?AiPreset;

    /**
     * Get all presets
     *
     * @return Collection<AiPreset> All presets ordered by name
     */
    public function getAllPresets(): Collection;

    /**
     * Get only active presets
     *
     * @return Collection<AiPreset> Active presets
     */
    public function getActivePresets(): Collection;

    /**
     * Get presets filtered by engine
     *
     * @param string $engineName Engine name
     * @return Collection<AiPreset> Active presets for the specified engine
     */
    public function getPresetsByEngine(string $engineName): Collection;

    /**
     * Search presets by name or description
     *
     * @param string $query Search query
     * @return Collection<AiPreset> Matching active presets
     */
    public function searchPresets(string $query): Collection;

    /**
     * Set a preset as the default
     *
     * @param int $id Preset ID
     * @return bool True if set successfully
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When preset not found
     */
    public function setDefaultPreset(int $id): bool;

    /**
     * Duplicate an existing preset
     *
     * @param int $id Original preset ID
     * @param string|null $newName New name for the duplicated preset (auto-generated if null)
     * @return AiPreset The duplicated preset
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When original preset not found
     */
    public function duplicatePreset(int $id, ?string $newName = null): AiPreset;

    /**
     * Test preset configuration (enhanced method)
     *
     * @param int $id Preset ID
     * @return array Test results with preset info, success status, and response time
     * @throws \App\Exceptions\PresetException When preset not found
     */
    public function testPreset(int $id): array;

    /**
     * Test preset configuration (legacy method - for backward compatibility)
     *
     * @param int $id Preset ID
     * @return array Test results including success status, error message, and response time
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When preset not found
     */
    public function testPresetConfiguration(int $id): array;

    /**
     * Import recommended preset from engine
     *
     * @param string $engineName Engine name
     * @param int $presetIndex Index of recommended preset
     * @return AiPreset The imported preset
     * @throws \App\Exceptions\PresetException When engine or preset not found
     */
    public function importRecommendedPreset(string $engineName, int $presetIndex): AiPreset;

    /**
     * Get default configuration for an engine
     *
     * @param string $engineName Engine name
     * @return array Default configuration
     * @throws \Exception When engine doesn't exist
     */
    public function getEngineDefaults(string $engineName): array;

    /**
     * Validate engine configuration (returns array of errors)
     *
     * @param string $engineName Engine name
     * @param array $config Configuration to validate
     * @return array Validation results with errors if any
     * @throws \Exception When engine doesn't exist
     */
    public function validateEngineConfig(string $engineName, array $config): array;

    /**
     * Validate engine configuration (throws exception on errors)
     *
     * @param string $engineName Engine name
     * @param array $config Configuration to validate
     * @return void
     * @throws \App\Exceptions\PresetException When validation fails
     */
    public function validateEngineConfigData(string $engineName, array $config): void;

    /**
     * Test engine configuration by connecting to the engine
     *
     * @param string $engineName Engine name
     * @param array $config Configuration to test
     * @return array Test results including success status, error message, and response time
     */
    public function testEngineConfiguration(string $engineName, array $config): array;

    /**
     * Get available engines with their metadata
     *
     * @return array Engines with name, display_name, default_config, enabled status
     */
    public function getAvailableEngines(): array;

    /**
     * Get comprehensive preset statistics
     *
     * @return array Statistics including totals, counts by engine, default preset info
     */
    public function getPresetStatistics(): array;
}

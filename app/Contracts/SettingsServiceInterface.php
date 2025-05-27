<?php

namespace App\Contracts;

use Illuminate\Http\Request;

interface SettingsServiceInterface
{
    /**
     * Get all configured settings with their values from database
     *
     * @return array
     */
    public function getAllSettings(): array;

    /**
     * Get settings grouped by their group configuration
     *
     * @return array
     */
    public function getGroupedSettings(): array;

    /**
     * Get validation rules for all settings
     *
     * @return array
     */
    public function getValidationRules(): array;

    /**
     * Get validation rules for specific fields
     *
     * @param array $fields
     * @return array
     */
    public function getValidationRulesForFields(array $fields): array;

    /**
     * Get default values for all settings
     *
     * @return array
     */
    public function getDefaultValues(): array;

    /**
     * Get field configuration by key
     *
     * @param string $key
     * @return array|null
     */
    public function getFieldConfig(string $key): ?array;

    /**
     * Check if field exists in configuration
     *
     * @param string $key
     * @return bool
     */
    public function hasField(string $key): bool;

    /**
     * Save multiple settings at once
     *
     * @param array $values
     * @return bool
     */
    public function saveSettings(array $values): bool;

    /**
     * Validate and save settings from request
     *
     * @param Request $request
     * @return array ['success' => bool, 'errors' => array|null, 'message' => string|null]
     */
    public function validateAndSaveSettings($request): array;

    /**
     * Get settings for frontend (with translated labels if needed)
     *
     * @return array
     */
    public function getSettingsForFrontend(): array;
}

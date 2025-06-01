<?php

namespace App\Services\Settings;

use App\Contracts\Settings\OptionsServiceInterface;
use App\Contracts\Settings\SettingsServiceInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SettingsService implements SettingsServiceInterface
{
    /**
     * Create a new service instance.
     *
     * @param OptionsServiceInterface $optionsService
     */
    public function __construct(
        protected OptionsServiceInterface $optionsService
    ) {
    }

    /**
     * Get all configured settings with their values from database
     *
     * @return array
     */
    public function getAllSettings(): array
    {
        $fields = config('settings.fields', []);
        $settings = [];

        foreach ($fields as $key => $config) {
            $value = $this->optionsService->get($key, $config['default'] ?? null);

            $settings[$key] = [
                'key' => $key,
                'value' => $value,
                'config' => $config,
            ];
        }

        // Sort by order within groups, then by group order
        return $this->sortSettings($settings);
    }

    /**
     * Get settings grouped by their group configuration
     *
     * @return array
     */
    public function getGroupedSettings(): array
    {
        $settings = $this->getAllSettings();
        $groups = config('settings.groups', []);
        $grouped = [];

        // Initialize groups
        foreach ($groups as $groupKey => $groupConfig) {
            $grouped[$groupKey] = [
                'config' => $groupConfig,
                'fields' => []
            ];
        }

        // Group settings
        foreach ($settings as $setting) {
            $groupKey = $setting['config']['group'] ?? 'general';

            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'config' => [
                        'label' => ucfirst($groupKey),
                        'description' => '',
                        'icon' => 'settings',
                        'order' => 999
                    ],
                    'fields' => []
                ];
            }

            $grouped[$groupKey]['fields'][] = $setting;
        }

        // Sort groups by order
        uasort($grouped, function ($a, $b) {
            return ($a['config']['order'] ?? 999) <=> ($b['config']['order'] ?? 999);
        });

        // Sort fields within each group
        foreach ($grouped as &$group) {
            usort($group['fields'], function ($a, $b) {
                return ($a['config']['order'] ?? 999) <=> ($b['config']['order'] ?? 999);
            });
        }

        return $grouped;
    }

    /**
     * Get validation rules for all settings
     *
     * @return array
     */
    public function getValidationRules(): array
    {
        $fields = config('settings.fields', []);
        $rules = [];

        foreach ($fields as $key => $config) {
            if (isset($config['validation'])) {
                $rules[$key] = $config['validation'];
            }
        }

        return $rules;
    }

    /**
     * Get validation rules for specific fields
     *
     * @param array $fields
     * @return array
     */
    public function getValidationRulesForFields(array $fields): array
    {
        $allRules = $this->getValidationRules();

        return array_intersect_key($allRules, array_flip($fields));
    }

    /**
     * Get default values for all settings
     *
     * @return array
     */
    public function getDefaultValues(): array
    {
        $fields = config('settings.fields', []);
        $defaults = [];

        foreach ($fields as $key => $config) {
            $defaults[$key] = $config['default'] ?? null;
        }

        return $defaults;
    }

    /**
     * Get field configuration by key
     *
     * @param string $key
     * @return array|null
     */
    public function getFieldConfig(string $key): ?array
    {
        return config("settings.fields.{$key}");
    }

    /**
     * Check if field exists in configuration
     *
     * @param string $key
     * @return bool
     */
    public function hasField(string $key): bool
    {
        return config("settings.fields.{$key}") !== null;
    }

    /**
     * Validate and save settings from request
     *
     * @param \Illuminate\Http\Request $request
     * @return array ['success' => bool, 'errors' => array|null, 'message' => string|null]
     */
    public function validateAndSaveSettings($request): array
    {
        try {
            // Get only fields that exist in configuration
            $fieldsToValidate = array_keys(
                array_intersect_key(
                    $request->all(),
                    config('settings.fields', [])
                )
            );

            // Get validation rules for submitted fields
            $rules = $this->getValidationRulesForFields($fieldsToValidate);

            // Create validator with custom messages
            $validator = Validator::make($request->all(), $rules, [
                'max' => 'The :attribute field must not exceed :max characters.',
                'required' => 'The :attribute field is required.',
                'integer' => 'The :attribute field must be a number.',
                'min' => 'The :attribute field must be at least :min.',
                'boolean' => 'The :attribute field must be true or false.',
            ]);

            // Check validation
            if ($validator->fails()) {
                return [
                    'success' => false,
                    'errors' => $validator->errors(),
                    'message' => null
                ];
            }

            // Save settings
            $validated = $validator->validated();
            $success = $this->saveSettingsValidated($validated);

            if ($success) {
                return ['success' => true];
            } else {
                return [
                    'success' => false,
                    'errors' => null,
                    'message' => 'There was an error saving settings'
                ];
            }

        } catch (\Exception $e) {
            Log::error('Settings validation and save error', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return [
                'success' => false,
                'errors' => null,
                'message' => 'There was an error saving settings: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Save multiple settings at once
     *
     * @param array $values
     * @return bool
     */
    public function saveSettings(array $values): bool
    {
        try {
            foreach ($values as $key => $value) {
                if (!$this->hasField($key)) {
                    continue; // Skip unknown fields
                }

                // Cast value according to field type
                $value = $this->castValueForStorage($key, $value);

                $this->optionsService->set($key, $value);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to save settings', [
                'error' => $e->getMessage(),
                'values' => $values
            ]);

            return false;
        }
    }

    /**
     * Save multiple settings at once (already validated data)
     *
     * @param array $values
     * @return bool
     */
    public function saveSettingsValidated(array $values): bool
    {
        try {
            foreach ($values as $key => $value) {
                // Cast value according to field type
                $value = $this->castValueForStorage($key, $value);
                $this->optionsService->set($key, $value);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to save validated settings', [
                'error' => $e->getMessage(),
                'values' => $values
            ]);

            return false;
        }
    }

    /**
     * Get settings for frontend (with icons and processed config)
     *
     * @return array
     */
    public function getSettingsForFrontend(): array
    {
        $grouped = $this->getGroupedSettings();
        $icons = config('settings.icons', []);

        // Process and add icon SVG paths
        foreach ($grouped as &$group) {
            // Add group icon SVG
            $groupIconKey = $group['config']['icon'] ?? 'settings';
            $group['config']['icon_svg'] = $icons[$groupIconKey] ?? $icons['settings'];

            foreach ($group['fields'] as &$field) {
                // Add field icon SVG (only if icon is specified)
                if (!empty($field['config']['icon'])) {
                    $fieldIconKey = $field['config']['icon'];
                    $field['config']['icon_svg'] = $icons[$fieldIconKey] ?? null;
                } else {
                    $field['config']['icon_svg'] = null;
                }

                // Process options for select fields
                if ($field['config']['type'] === 'select' && isset($field['config']['options'])) {
                    $field['config']['processed_options'] = [];
                    foreach ($field['config']['options'] as $optionKey => $optionLabel) {
                        $field['config']['processed_options'][] = [
                            'value' => $optionKey,
                            'label' => $optionLabel
                        ];
                    }
                }
            }
        }

        return $grouped;
    }

    /**
     * Sort settings by group order and field order
     *
     * @param array $settings
     * @return array
     */
    protected function sortSettings(array $settings): array
    {
        $groups = config('settings.groups', []);

        uasort($settings, function ($a, $b) use ($groups) {
            $groupA = $a['config']['group'] ?? 'general';
            $groupB = $b['config']['group'] ?? 'general';

            $groupOrderA = $groups[$groupA]['order'] ?? 999;
            $groupOrderB = $groups[$groupB]['order'] ?? 999;

            if ($groupOrderA !== $groupOrderB) {
                return $groupOrderA <=> $groupOrderB;
            }

            $fieldOrderA = $a['config']['order'] ?? 999;
            $fieldOrderB = $b['config']['order'] ?? 999;

            return $fieldOrderA <=> $fieldOrderB;
        });

        return $settings;
    }

    /**
     * Cast value for storage based on field type
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function castValueForStorage(string $key, $value)
    {
        $config = $this->getFieldConfig($key);

        if (!$config) {
            return $value;
        }

        switch ($config['type']) {
            case 'checkbox':
                return (bool) $value;

            case 'input':
                if (($config['input_type'] ?? 'text') === 'number') {
                    // Check if it should be float or integer
                    if (isset($config['step']) && $config['step'] != 1) {
                        return (float) $value;
                    }
                    return (int) $value;
                }
                return (string) $value;

            case 'textarea':
            case 'select':
            default:
                return (string) $value;
        }
    }
}

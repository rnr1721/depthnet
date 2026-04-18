<?php

namespace App\Services\Agent\Plugins\Traits;

/**
 * Trait for plugins that need language configuration.
 *
 * Provides a standard list of supported languages and helper methods
 * for consistent language handling across memory plugins.
 *
 * Note: VectorMemoryPlugin has its own specialized handling due to
 * additional 'multilingual' mode and different field naming.
 */
trait PluginHasLanguageSettingsTrait
{
    /**
     * Supported languages for plugin content.
     * Maps language code to display name.
     */
    protected array $supportedLanguages = [
        'auto' => 'Auto-detect language',
        'en'   => 'English',
        'ru'   => 'Russian',
        'de'   => 'German',
        'fr'   => 'French',
        'es'   => 'Spanish',
    ];

    /**
     * Get the configuration field for language selection.
     *
     * @param string $label      Field label
     * @param string $description Field description
     * @return array
     */
    protected function getLanguageConfigField(
        string $label = 'Language',
        string $description = 'Force language for entries. Model will be instructed accordingly.'
    ): array {
        return [
            'type'        => 'select',
            'label'       => $label,
            'description' => $description,
            'options'     => $this->supportedLanguages,
            'value'       => 'auto',
            'required'    => false,
        ];
    }

    /**
     * Check if a specific language is forced (not auto).
     *
     * @param array $config Plugin configuration
     * @param string $fieldName Config field name
     * @return bool
     */
    protected function isLanguageForced(array $config, string $fieldName): bool
    {
        $lang = $config[$fieldName] ?? 'auto';
        return $lang !== 'auto';
    }

    /**
     * Get the forced language code.
     *
     * @param array $config Plugin configuration
     * @param string $fieldName Config field name
     * @return string|null Language code, or null if auto
     */
    protected function getForcedLanguageCode(array $config, string $fieldName): ?string
    {
        $lang = $config[$fieldName] ?? 'auto';
        return $lang !== 'auto' ? $lang : null;
    }

    /**
     * Get the forced language display name.
     *
     * @param array $config Plugin configuration
     * @param string $fieldName Config field name
     * @return string|null Language display name, or null if auto
     */
    protected function getForcedLanguageName(array $config, string $fieldName): ?string
    {
        $lang = $config[$fieldName] ?? 'auto';

        if ($lang === 'auto') {
            return null;
        }

        return $this->supportedLanguages[$lang] ?? strtoupper($lang);
    }

    /**
     * Build language instruction for tool schema description.
     *
     * @param array $config Plugin configuration
     * @param string $fieldName Config field name
     * @return string Empty string if auto, otherwise instruction like " ALL entries MUST be written in Russian. "
     */
    protected function buildLanguageInstruction(array $config, string $fieldName): string
    {
        $langName = $this->getForcedLanguageName($config, $fieldName);

        if ($langName === null) {
            return '';
        }

        return " ALL entries MUST be written in {$langName}. ";
    }

    /**
     * Build warning message for getInstructions().
     *
     * @param array $config Plugin configuration
     * @param string $fieldName Config field name
     * @param string $itemName Name of the item being stored (e.g., 'memory entries', 'journal entries')
     * @return string|null Warning string or null if language is auto
     */
    protected function buildLanguageWarning(array $config, string $fieldName, string $itemName = 'entries'): ?string
    {
        $langName = $this->getForcedLanguageName($config, $fieldName);

        if ($langName === null) {
            return null;
        }

        return "⚠️ All {$itemName} MUST be written in {$langName}.";
    }

    /**
     * Get default config value for a language field.
     *
     * @param string $fieldName Config field name
     * @return array
     */
    protected function getDefaultLanguageConfig(string $fieldName): array
    {
        return [
            $fieldName => 'auto',
        ];
    }
}

<?php

namespace App\Contracts\Agent\Models;

use App\Contracts\Agent\AiModelRequestInterface;
use App\Contracts\Agent\AiModelResponseInterface;

/**
 * Interface AIModelInterface
 */
interface AIModelEngineInterface
{
    /**
     * Generate a response based on the context
     *
     * @param AiModelRequestInterface $request Model request parameters
     * @return AiModelResponseInterface
     */
    public function generate(
        AiModelRequestInterface $request
    ): AiModelResponseInterface;

    /**
     * Get the model name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get engine display name for UI
     */
    public function getDisplayName(): string;

    /**
     * Get engine description
     */
    public function getDescription(): string;

    /**
     * Get configuration fields metadata for dynamic form generation
     *
     * @return array Format:
     * [
     *     'field_name' => [
     *         'type' => 'text|number|select|textarea|password|url',
     *         'label' => 'Human readable label',
     *         'description' => 'Field description',
     *         'required' => true|false,
     *         'placeholder' => 'Placeholder text',
     *         'options' => ['key' => 'value'], // for select fields
     *         'min' => 0, // for number fields
     *         'max' => 100, // for number fields
     *         'step' => 0.1, // for number fields
     *         'rows' => 6, // for textarea fields
     *     ]
     * ]
     */
    public function getConfigFields(): array;

    /**
     * Get available models dynamically
     * This method should handle cases where API key is required or not
     *
     * @param array|null $config Optional config containing API key and other params
     * @return array Format:
     * [
     *     'model_id' => [
     *         'id' => 'model_id',
     *         'display_name' => 'Human readable name',
     *         'description' => 'Model description (optional)',
     *         'context_length' => 4096, // optional
     *         'owned_by' => 'company', // optional
     *         'category' => 'general|reasoning|creative|coding', // optional
     *         'recommended' => true|false, // optional
     *         'source' => 'api|fallback', // optional
     *     ]
     * ]
     */
    public function getAvailableModels(?array $config = null): array;

    /**
     * Check if this engine supports dynamic model loading
     *
     * @return bool
     */
    public function supportsDynamicModels(): bool;

    /**
     * Check if API key is required for loading models
     *
     * @return bool
     */
    public function requiresApiKeyForModels(): bool;

    /**
     * Get recommended preset configurations
     *
     * @return array Format:
     * [
     *     [
     *         'name' => 'Preset name',
     *         'description' => 'Preset description',
     *         'config' => ['field' => 'value']
     *     ]
     * ]
     */
    public function getRecommendedPresets(): array;

    /**
     * Get default configuration values
     */
    public function getDefaultConfig(): array;

    /**
     * Validate configuration array
     *
     * @param array $config Configuration to validate
     * @return array Errors array, empty if valid
     */
    public function validateConfig(array $config): array;

    /**
     * Test connection to the engine (optional)
     *
     * @return bool True if connection successful
     */
    public function testConnection(): bool;
}

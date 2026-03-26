<?php

namespace App\Contracts\Agent\Capabilities;

/**
 * Base contract for all capability providers (embedding, image, audio, ...).
 *
 * Capability providers are distinct from chat engine providers (NovitaModel,
 * ClaudeModel, etc.) — they handle specific media/service tasks rather than
 * conversational generation.
 *
 * Each provider must declare its driver name and expose config field
 * definitions so the GUI can render a settings form without hardcoding
 * any provider-specific knowledge.
 */
interface CapabilityProviderInterface
{
    /**
     * Unique driver identifier used in preset_capability_configs.driver.
     * Example: 'novita', 'openai', 'cohere'
     */
    public function getDriverName(): string;

    /**
     * Human-readable name shown in the GUI.
     * Example: 'Novita AI', 'OpenAI'
     */
    public function getDisplayName(): string;

    /**
     * Config field definitions for GUI form rendering.
     *
     * Returns an associative array keyed by field name.
     * Each field has at minimum: type, label, required.
     *
     * Supported types: 'text', 'password', 'select', 'number', 'url'
     *
     * Example:
     * [
     *   'api_key' => ['type' => 'password', 'label' => 'API Key', 'required' => true],
     *   'model'   => ['type' => 'select',   'label' => 'Model',   'required' => true,
     *                 'options' => ['baai/bge-m3' => 'BGE-M3', ...]],
     * ]
     *
     * @return array<string, array<string, mixed>>
     */
    public function getConfigFields(): array;

    /**
     * Default config values used when no preset config exists yet.
     *
     * @return array<string, mixed>
     */
    public function getDefaultConfig(): array;

    /**
     * Validate a config array. Returns an array of validation errors.
     * Empty array means config is valid.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string> field => error message
     */
    public function validateConfig(array $config): array;
}

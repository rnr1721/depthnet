<?php

namespace App\Contracts\Agent\Workspace;

use App\Models\AiPreset;

interface WorkspaceServiceInterface
{
    /**
     * Set or overwrite a workspace key.
     *
     * @param AiPreset $preset
     * @param string   $key
     * @param string   $value
     * @return bool
     */
    public function set(AiPreset $preset, string $key, string $value): bool;

    /**
     * Append text to an existing key (creates the key if missing).
     *
     * @param AiPreset $preset
     * @param string   $key
     * @param string   $value
     * @return bool
     */
    public function append(AiPreset $preset, string $key, string $value): bool;

    /**
     * Get the value of a single key, or null if it does not exist.
     *
     * @param AiPreset $preset
     * @param string   $key
     * @return string|null
     */
    public function get(AiPreset $preset, string $key): ?string;

    /**
     * Delete a single key.
     *
     * @param AiPreset $preset
     * @param string   $key
     * @return bool
     */
    public function delete(AiPreset $preset, string $key): bool;

    /**
     * Delete all keys for the preset.
     *
     * @param AiPreset $preset
     * @return bool
     */
    public function clear(AiPreset $preset): bool;

    /**
     * Return all key-value pairs for the preset, ordered by key.
     *
     * @param AiPreset $preset
     * @return array<string, string>
     */
    public function all(AiPreset $preset): array;

    /**
     * Return the entire workspace formatted as a string
     * ready to be injected into a system prompt placeholder.
     *
     * @param AiPreset $preset
     * @return string  Empty string when workspace is empty.
     */
    public function getFormatted(AiPreset $preset): string;
}

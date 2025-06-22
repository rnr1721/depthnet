<?php

namespace App\Contracts\Agent\Memory;

use Illuminate\Support\Collection;
use App\Models\AiPreset;

interface MemoryServiceInterface
{
    /**
     * Get all memory items for a preset, ordered by position
     *
     * @param AiPreset $preset
     * @return Collection
     */
    public function getMemoryItems(AiPreset $preset): Collection;

    /**
     * Get memory content as formatted numbered list
     *
     * @param AiPreset $preset
     * @return string
     */
    public function getFormattedMemory(AiPreset $preset): string;

    /**
     * Add new memory item to the end of the list
     *
     * @param AiPreset $preset
     * @param string $content
     * @param array $config
     * @return array
     */
    public function addMemoryItem(AiPreset $preset, string $content, array $config = []): array;

    /**
     * Replace all memory content with new content
     *
     * @param AiPreset $preset
     * @param string $content
     * @param array $config
     * @return array
     */
    public function replaceMemory(AiPreset $preset, string $content, array $config = []): array;

    /**
     * Delete specific memory item by number (1-indexed)
     *
     * @param AiPreset $preset
     * @param integer $itemNumber
     * @param array $config
     * @return array
     */
    public function deleteMemoryItem(AiPreset $preset, int $itemNumber, array $config = []): array;

    /**
     * Clear all memory items for a preset
     *
     * @param AiPreset $preset
     * @param array $config
     * @return array
     */
    public function clearMemory(AiPreset $preset, array $config = []): array;

    /**
     * Get memory statistics
     *
     * @param AiPreset $preset
     * @param array $config
     * @return array
     */
    public function getMemoryStats(AiPreset $preset, array $config = []): array;

    /**
     * Search memory items by content
     *
     * @param AiPreset $preset
     * @param string $query
     * @return Collection
     */
    public function searchMemory(AiPreset $preset, string $query): Collection;

    /**
     * Get memory content for AI context (with optional limit)
     *
     * @param AiPreset $preset
     * @param integer|null $maxLength
     * @return string
     */
    public function getMemoryForContext(AiPreset $preset, ?int $maxLength = null): string;

    /**
     * Get memory limit from config
     *
     * @param array $config
     * @return integer
     */
    public function getMemoryLimit(array $config): int;

    /**
     * Check if auto cleanup is enabled
     *
     * @param array $config
     * @return boolean
     */
    public function isAutoCleanupEnabled(array $config): bool;

    /**
     * Get cleanup strategy from config
     *
     * @param array $config
     * @return string
     */
    public function getCleanupStrategy(array $config): string;

    /**
     * Check if versioning is enabled
     *
     * @param array $config
     * @return boolean
     */
    public function isVersioningEnabled(array $config): bool;

    /**
     * Get max versions from config
     *
     * @param array $config
     * @return integer
     */
    public function getMaxVersions(array $config): int;

}

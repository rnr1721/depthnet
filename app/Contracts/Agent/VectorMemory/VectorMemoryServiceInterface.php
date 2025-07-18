<?php

namespace App\Contracts\Agent\VectorMemory;

use App\Models\AiPreset;
use App\Models\VectorMemory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Interface for Vector Memory Service
 * Defines contract for vector memory operations
 */
interface VectorMemoryServiceInterface
{
    /**
     * Get paginated vector memories using Laravel pagination
     *
     * @param AiPreset $preset
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginatedVectorMemories(AiPreset $preset, int $perPage = 20): LengthAwarePaginator;

    /**
     * Get vector memories for preset
     *
     * @param AiPreset $preset
     * @param int|null $limit
     * @return Collection
     */
    public function getVectorMemories(AiPreset $preset, ?int $limit = null): Collection;

    /**
     * Store content in vector memory
     *
     * @param AiPreset $preset
     * @param string $content
     * @param array $config Plugin configuration
     * @return array Result with success status and message
     */
    public function storeVectorMemory(AiPreset $preset, string $content, array $config = []): array;

    /**
     * Search vector memories by semantic similarity
     *
     * @param AiPreset $preset
     * @param string $query
     * @param array $config Plugin configuration
     * @return array Result with search results
     */
    public function searchVectorMemories(AiPreset $preset, string $query, array $config = []): array;

    /**
     * Get recent vector memories
     *
     * @param AiPreset $preset
     * @param int $limit
     * @return array Result with recent memories
     */
    public function getRecentVectorMemories(AiPreset $preset, int $limit = 5): array;

    /**
     * Delete specific vector memory
     *
     * @param AiPreset $preset
     * @param int $memoryId
     * @return array Result with success status
     */
    public function deleteVectorMemory(AiPreset $preset, int $memoryId): array;

    /**
     * Clear all vector memories for preset
     *
     * @param AiPreset $preset
     * @return array Result with success status
     */
    public function clearVectorMemories(AiPreset $preset): array;

    /**
     * Get vector memory statistics
     *
     * @param AiPreset $preset
     * @param array $config Plugin configuration
     * @return array Statistics data
     */
    public function getVectorMemoryStats(AiPreset $preset, array $config = []): array;

    /**
     * Update memory importance
     *
     * @param AiPreset $preset
     * @param int $memoryId
     * @param float $importance
     * @return array Result with success status
     */
    public function updateVectorMemoryImportance(AiPreset $preset, int $memoryId, float $importance): array;

    /**
     * Get vector memory by ID
     *
     * @param AiPreset $preset
     * @param int $memoryId
     * @return VectorMemory|null
     */
    public function getVectorMemoryById(AiPreset $preset, int $memoryId): ?VectorMemory;

    /**
     * Search memories by specific keywords
     *
     * @param AiPreset $preset
     * @param array $keywords
     * @return Collection
     */
    public function searchByKeywords(AiPreset $preset, array $keywords): Collection;

    /**
     * Test vector memory service connection and functionality
     *
     * @param AiPreset $preset
     * @return array
     */
    public function testConnection(AiPreset $preset): array;

    /**
     * Export vector memories using dedicated exporter service
     *
     * @param AiPreset $preset
     * @return array
     */
    public function exportVectorMemories(AiPreset $preset): array;

    /**
     * Import vector memories using dedicated importer service
     *
     * @param AiPreset $preset
     * @param string $content
     * @param bool $isJson
     * @param bool $replaceExisting
     * @param array $config
     * @return array
     */
    public function importVectorMemories(
        AiPreset $preset,
        string $content,
        bool $isJson,
        bool $replaceExisting,
        array $config
    ): array;
}

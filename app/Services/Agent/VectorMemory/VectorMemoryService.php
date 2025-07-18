<?php

namespace App\Services\Agent\VectorMemory;

use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryServiceInterface;
use App\Models\AiPreset;
use App\Models\VectorMemory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Service for managing vector memory operations
 * Handles CRUD operations, semantic search, and memory limit enforcement
 */
class VectorMemoryService implements VectorMemoryServiceInterface
{
    public function __construct(
        protected LoggerInterface $logger,
        protected TfIdfServiceInterface $tfIdfService,
        protected VectorMemoryImporter $importer,
        protected VectorMemoryExporter $exporter,
        protected VectorMemory $vectorMemoryModel
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getPaginatedVectorMemories(AiPreset $preset, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = max(10, min(100, $perPage));

        return $this->vectorMemoryModel
            ->where('preset_id', $preset->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @inheritDoc
     */
    public function getVectorMemories(AiPreset $preset, ?int $limit = null): Collection
    {
        $query = $this->vectorMemoryModel->where('preset_id', $preset->id)
            ->orderBy('created_at', 'desc');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * @inheritDoc
     */
    public function storeVectorMemory(AiPreset $preset, string $content, array $config = []): array
    {
        try {
            $content = trim($content);
            if (empty($content)) {
                return [
                    'success' => false,
                    'message' => 'Error: Cannot store empty content.'
                ];
            }

            // Check if we need to cleanup old entries
            $this->cleanupIfNeeded($preset, $config);

            // Configure TF-IDF service with custom language settings
            $this->configureTfIdfService($config);

            // Generate TF-IDF vector
            $language = $this->determineLanguage($content, $config);
            $vector = $this->tfIdfService->vectorize($content);
            $keywords = $this->extractKeywords($content, $language);

            // Store in database
            $vectorMemory = $this->vectorMemoryModel->create([
                'preset_id' => $preset->id,
                'content' => $content,
                'tfidf_vector' => $vector,
                'keywords' => $keywords,
                'importance' => 1.0
            ]);

            return [
                'success' => true,
                'message' => "Content stored in vector memory successfully. Generated " . count($vector) . " features (language: {$language}).",
                'memory' => $vectorMemory,
                'language' => $language,
                'features_count' => count($vector)
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::storeVectorMemory error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error storing content: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function searchVectorMemories(AiPreset $preset, string $query, array $config = []): array
    {
        try {
            $query = trim($query);
            if (empty($query)) {
                return [
                    'success' => false,
                    'message' => 'Error: Search query cannot be empty.'
                ];
            }

            $memories = $this->getVectorMemories($preset);

            if ($memories->isEmpty()) {
                return [
                    'success' => true,
                    'message' => 'No memories found. Store some content first.',
                    'results' => []
                ];
            }

            $results = $this->tfIdfService->findSimilar(
                $query,
                $memories,
                $config['search_limit'] ?? 5,
                $config['similarity_threshold'] ?? 0.1,
                $config['boost_recent'] ?? true
            );

            return [
                'success' => true,
                'message' => "Found " . count($results) . " similar memories.",
                'results' => $results,
                'total_searched' => $memories->count()
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::searchVectorMemories error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error searching memories: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getRecentVectorMemories(AiPreset $preset, int $limit = 5): array
    {
        try {
            $limit = max(1, min($limit, 20));
            $memories = $this->getVectorMemories($preset, $limit);

            return [
                'success' => true,
                'message' => "Retrieved {$memories->count()} recent memories.",
                'memories' => $memories
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::getRecentVectorMemories error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error retrieving recent memories: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteVectorMemory(AiPreset $preset, int $memoryId): array
    {
        try {
            $memory = $this->vectorMemoryModel->where('preset_id', $preset->id)
                ->where('id', $memoryId)
                ->first();

            if (!$memory) {
                return [
                    'success' => false,
                    'message' => 'Memory not found.'
                ];
            }

            $memory->delete();

            return [
                'success' => true,
                'message' => 'Vector memory deleted successfully.'
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::deleteVectorMemory error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error deleting memory: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function clearVectorMemories(AiPreset $preset): array
    {
        try {
            $count = $this->vectorMemoryModel->where('preset_id', $preset->id)->count();
            $this->vectorMemoryModel->where('preset_id', $preset->id)->delete();

            return [
                'success' => true,
                'message' => "Cleared {$count} vector memories successfully.",
                'deleted_count' => $count
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::clearVectorMemories error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error clearing memories: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getVectorMemoryStats(AiPreset $preset, array $config = []): array
    {
        try {
            $memories = $this->getVectorMemories($preset);
            $totalCount = $memories->count();
            $maxEntries = $config['max_entries'] ?? 1000;

            // Calculate average vector size and vocabulary
            $avgVectorSize = 0;
            $totalFeatures = 0;
            $allWords = [];

            foreach ($memories as $memory) {
                $vector = $memory->tfidf_vector;
                $totalFeatures += count($vector);
                $allWords = array_merge($allWords, array_keys($vector));
            }

            if ($totalCount > 0) {
                $avgVectorSize = $totalFeatures / $totalCount;
            }

            $vocabularySize = count(array_unique($allWords));

            return [
                'total_memories' => $totalCount,
                'max_entries' => $maxEntries,
                'usage_percentage' => $maxEntries > 0 ? round(($totalCount / $maxEntries) * 100, 2) : 0,
                'average_vector_size' => round($avgVectorSize, 1),
                'vocabulary_size' => $vocabularySize,
                'is_near_limit' => $totalCount > ($maxEntries * 0.8),
                'is_over_limit' => $totalCount > $maxEntries,
                'oldest_memory' => $memories->isNotEmpty() ? $memories->last()->created_at : null,
                'newest_memory' => $memories->isNotEmpty() ? $memories->first()->created_at : null
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::getVectorMemoryStats error: " . $e->getMessage());
            return [
                'total_memories' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function updateVectorMemoryImportance(AiPreset $preset, int $memoryId, float $importance): array
    {
        try {
            $memory = $this->vectorMemoryModel->where('preset_id', $preset->id)
                ->where('id', $memoryId)
                ->first();

            if (!$memory) {
                return [
                    'success' => false,
                    'message' => 'Memory not found.'
                ];
            }

            $importance = max(0.1, min(5.0, $importance)); // Clamp between 0.1 and 5.0
            $memory->update(['importance' => $importance]);

            return [
                'success' => true,
                'message' => 'Memory importance updated successfully.',
                'memory' => $memory
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::updateVectorMemoryImportance error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error updating memory importance: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getVectorMemoryById(AiPreset $preset, int $memoryId): ?VectorMemory
    {
        return $this->vectorMemoryModel->where('preset_id', $preset->id)
            ->where('id', $memoryId)
            ->first();
    }

    /**
     * @inheritDoc
     */
    public function searchByKeywords(AiPreset $preset, array $keywords): Collection
    {
        $query = $this->vectorMemoryModel->where('preset_id', $preset->id);

        foreach ($keywords as $keyword) {
            $query->whereJsonContains('keywords', $keyword);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Configure TF-IDF service with custom language settings
     *
     * @param array $config
     * @return void
     */
    protected function configureTfIdfService(array $config): void
    {
        $languageConfig = [];

        // Add custom Russian stop words
        if (!empty($config['custom_stop_words_ru'])) {
            $customRu = array_map('trim', explode(',', $config['custom_stop_words_ru']));
            $languageConfig['ru']['stop_words'] = array_merge(
                $languageConfig['ru']['stop_words'] ?? [],
                $customRu
            );
        }

        // Add custom English stop words
        if (!empty($config['custom_stop_words_en'])) {
            $customEn = array_map('trim', explode(',', $config['custom_stop_words_en']));
            $languageConfig['en']['stop_words'] = array_merge(
                $languageConfig['en']['stop_words'] ?? [],
                $customEn
            );
        }

        if (!empty($languageConfig)) {
            $this->tfIdfService->setLanguageConfig(['languages' => $languageConfig]);
        }
    }

    /**
     * Determine language for content based on config
     *
     * @param string $content
     * @param array $config
     * @return string
     */
    protected function determineLanguage(string $content, array $config): string
    {
        $mode = $config['language_mode'] ?? 'auto';

        return match($mode) {
            'ru' => 'ru',
            'en' => 'en',
            'auto' => $this->tfIdfService->detectLanguage($content),
            'multilingual' => 'auto',
            default => 'auto'
        };
    }

    /**
     * Extract keywords from content
     *
     * @param string $content
     * @param string $language
     * @return array
     */
    protected function extractKeywords(string $content, string $language = 'auto'): array
    {
        $words = $this->tfIdfService->tokenize($content, $language);

        // Filter out very short words and get unique keywords
        $keywords = array_filter($words, function ($word) {
            return strlen($word) > 2;
        });

        return array_values(array_unique($keywords));
    }

    /**
     * Cleanup old entries if limit is reached
     *
     * @param AiPreset $preset
     * @param array $config
     * @return void
     */
    protected function cleanupIfNeeded(AiPreset $preset, array $config): void
    {
        if (!($config['auto_cleanup'] ?? true)) {
            return;
        }

        $maxEntries = $config['max_entries'] ?? 1000;
        $currentCount = $this->vectorMemoryModel->where('preset_id', $preset->id)->count();

        if ($currentCount >= $maxEntries) {
            $deleteCount = $currentCount - $maxEntries + 1;

            $this->vectorMemoryModel->where('preset_id', $preset->id)
                ->orderBy('created_at', 'asc')
                ->limit($deleteCount)
                ->delete();
        }
    }

    /**
     * Test vector memory service connection and functionality
     *
     * @param AiPreset $preset
     * @return array
     */
    public function testConnection(AiPreset $preset): array
    {
        try {
            // Test TF-IDF service
            $testContent = 'Vector memory test - ' . time();
            $vector = $this->tfIdfService->vectorize($testContent);

            if (!is_array($vector) || empty($vector)) {
                return [
                    'success' => false,
                    'message' => 'TF-IDF service is not working properly.'
                ];
            }

            // Test database operations
            $storeResult = $this->storeVectorMemory($preset, $testContent);
            if (!$storeResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Database storage test failed: ' . $storeResult['message']
                ];
            }

            // Test search
            $searchResult = $this->searchVectorMemories($preset, 'test');
            if (!$searchResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Search test failed: ' . $searchResult['message']
                ];
            }

            // Cleanup test data
            if (isset($storeResult['memory'])) {
                $this->deleteVectorMemory($preset, $storeResult['memory']->id);
            }

            return [
                'success' => true,
                'message' => 'Vector memory service is working correctly.',
                'features_generated' => count($vector)
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::testConnection error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Connection test failed: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function exportVectorMemories(AiPreset $preset): array
    {
        try {
            $memories = $this->getVectorMemories($preset);

            if ($memories->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'No vector memories to export.'
                ];
            }

            return $this->exporter->export($preset, $memories);

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryService::exportVectorMemories error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Export failed: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function importVectorMemories(
        AiPreset $preset,
        string $content,
        bool $isJson,
        bool $replaceExisting,
        array $config
    ): array {
        // Clear existing memories if requested
        if ($replaceExisting) {
            $clearResult = $this->clearVectorMemories($preset);
            if (!$clearResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to clear existing memories: ' . $clearResult['message']
                ];
            }
        }

        return $this->importer->importFromContent(
            $preset,
            $content,
            $isJson,
            false,
            $config,
            fn (AiPreset $preset, string $content, array $config) => $this->storeVectorMemory($preset, $content, $config)
        );
    }
}

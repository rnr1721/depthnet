<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryServiceInterface;
use Psr\Log\LoggerInterface;
use App\Services\Agent\Plugins\MemoryPlugin;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use App\Services\Agent\Plugins\Traits\PluginPresetTrait;

/**
 * VectorMemoryPlugin class
 *
 * VectorMemoryPlugin provides semantic search capabilities using TF-IDF vectorization.
 * It allows storing and searching memories by meaning, not just exact keywords.
 * Can optionally integrate with regular memory plugin for better discoverability.
 */
class VectorMemoryPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected LoggerInterface $logger,
        protected VectorMemoryServiceInterface $vectorMemoryService,
        protected MemoryServiceInterface $memoryService
    ) {
        $this->initializeConfig();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'vectormemory';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        $maxEntries = $this->config['max_entries'] ?? 1000;
        return "Semantic memory storage with TF-IDF search. Finds similar memories by meaning, not just keywords. Stores up to {$maxEntries} entries per preset.";
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        return [
            'Store important information: [vectormemory]Successfully optimized database queries using indexes[/vectormemory]',
            'Search by meaning: [vectormemory search]how to speed up code[/vectormemory]',
            'Show recent memories: [vectormemory recent]5[/vectormemory]',
            'Show full memory item by id: [vectormemory show]42[/vectormemory]',
            'Clear all memories: [vectormemory clear][/vectormemory]',
            'Delete by ID: [vectormemory delete]42[/vectormemory]',
            'Delete by content: [vectormemory delete]optimization query[/vectormemory]',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getCustomSuccessMessage(): ?string
    {
        return "Vector memory operation completed successfully.";
    }

    /**
     * @inheritDoc
     */
    public function getCustomErrorMessage(): ?string
    {
        return "Error: Vector memory operation failed. Check the syntax and try again.";
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Vector Memory Plugin',
                'description' => 'Allow semantic memory storage and search',
                'required' => false
            ],
            'max_entries' => [
                'type' => 'number',
                'label' => 'Max Memory Entries',
                'description' => 'Maximum number of vector memories to store',
                'min' => 100,
                'max' => 5000,
                'value' => 1000,
                'required' => false
            ],
            'similarity_threshold' => [
                'type' => 'number',
                'label' => 'Similarity Threshold',
                'description' => 'Minimum similarity score (0.0-1.0) for search results',
                'min' => 0.0,
                'max' => 1.0,
                'step' => 0.01,
                'value' => 0.1,
                'required' => false
            ],
            'search_limit' => [
                'type' => 'number',
                'label' => 'Search Results Limit',
                'description' => 'Maximum number of search results to return',
                'min' => 1,
                'max' => 20,
                'value' => 5,
                'required' => false
            ],
            'auto_cleanup' => [
                'type' => 'checkbox',
                'label' => 'Auto Cleanup Old Entries',
                'description' => 'Automatically remove oldest entries when limit is reached',
                'value' => true,
                'required' => false
            ],
            'boost_recent' => [
                'type' => 'checkbox',
                'label' => 'Boost Recent Memories',
                'description' => 'Give higher relevance to more recent memories',
                'value' => true,
                'required' => false
            ],
            'integrate_with_memory' => [
                'type' => 'checkbox',
                'label' => 'Integrate with Memory Plugin',
                'description' => 'Add reference links to regular memory when storing vector memories',
                'value' => false,
                'required' => false
            ],
            'memory_link_format' => [
                'type' => 'select',
                'label' => 'Memory Link Format',
                'description' => 'How to format memory links in regular memory',
                'options' => [
                    'short' => 'Short: "Vector: keyword1, keyword2"',
                    'descriptive' => 'Descriptive: "Vector memory about: brief description"',
                    'timestamped' => 'Timestamped: "[MM-DD HH:mm] Vector: keywords"'
                ],
                'value' => 'descriptive',
                'required' => false
            ],
            'max_link_keywords' => [
                'type' => 'number',
                'label' => 'Max Keywords in Link',
                'description' => 'Maximum number of keywords to show in memory link',
                'min' => 2,
                'max' => 10,
                'value' => 4,
                'required' => false
            ],
            'language_mode' => [
                'type' => 'select',
                'label' => 'Language Processing',
                'description' => 'How to handle different languages',
                'options' => [
                    'auto' => 'Auto-detect language',
                    'ru' => 'Force Russian',
                    'en' => 'Force English',
                    'multilingual' => 'Mixed languages'
                ],
                'value' => 'auto',
                'required' => false
            ],
            'display_content_length' => [
                'type' => 'number',
                'label' => 'Display Content Length',
                'description' => 'Maximum number of characters to show in search results',
                'min' => 100,
                'max' => 1000,
                'value' => 500,
                'required' => false
            ],
            'custom_stop_words_ru' => [
                'type' => 'textarea',
                'label' => 'Custom Russian Stop Words',
                'description' => 'Additional Russian stop words (comma-separated)',
                'placeholder' => 'слово1, слово2, слово3',
                'required' => false
            ],
            'custom_stop_words_en' => [
                'type' => 'textarea',
                'label' => 'Custom English Stop Words',
                'description' => 'Additional English stop words (comma-separated)',
                'placeholder' => 'word1, word2, word3',
                'required' => false
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['max_entries'])) {
            $maxEntries = (int) $config['max_entries'];
            if ($maxEntries < 100 || $maxEntries > 5000) {
                $errors['max_entries'] = 'Max entries must be between 100 and 5000';
            }
        }

        if (isset($config['similarity_threshold'])) {
            $threshold = (float) $config['similarity_threshold'];
            if ($threshold < 0.0 || $threshold > 1.0) {
                $errors['similarity_threshold'] = 'Similarity threshold must be between 0.0 and 1.0';
            }
        }

        if (isset($config['search_limit'])) {
            $limit = (int) $config['search_limit'];
            if ($limit < 1 || $limit > 20) {
                $errors['search_limit'] = 'Search limit must be between 1 and 20';
            }
        }

        if (isset($config['max_link_keywords'])) {
            $keywords = (int) $config['max_link_keywords'];
            if ($keywords < 2 || $keywords > 10) {
                $errors['max_link_keywords'] = 'Max keywords in link must be between 2 and 10';
            }
        }

        if (isset($config['display_content_length'])) {
            $length = (int) $config['display_content_length'];
            if ($length < 100 || $length > 1000) {
                $errors['display_content_length'] = 'Display content length must be between 100 and 1000';
            }
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'max_entries' => 1000,
            'similarity_threshold' => 0.1,
            'search_limit' => 5,
            'auto_cleanup' => true,
            'boost_recent' => true,
            'integrate_with_memory' => false,
            'memory_link_format' => 'descriptive',
            'max_link_keywords' => 4,
            'language_mode' => 'auto',
            'display_content_length' => 500,
            'custom_stop_words_ru' => '',
            'custom_stop_words_en' => ''
        ];
    }

    /**
     * @inheritDoc
     */
    public function testConnection(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $result = $this->vectorMemoryService->testConnection($this->preset);
            return $result['success'];
        } catch (\Exception $e) {
            $this->logger->error("VectorMemoryPlugin::testConnection error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function execute(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Vector memory plugin is disabled.";
        }

        return $this->store($content);
    }

    /**
     * Store content in vector memory
     *
     * @param string $content
     * @return string
     */
    public function store(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Vector memory plugin is disabled.";
        }

        try {
            $result = $this->vectorMemoryService->storeVectorMemory($this->preset, $content, $this->config);

            if (!$result['success']) {
                return $result['message'];
            }

            $message = $result['message'];

            // Add to regular memory if integration is enabled
            if ($this->config['integrate_with_memory'] ?? false) {
                $memoryLinkResult = $this->addToRegularMemory($result['memory']);
                if ($memoryLinkResult) {
                    $message .= " " . $memoryLinkResult;
                }
            }

            return $message;

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::store error: " . $e->getMessage());
            return "Error storing content: " . $e->getMessage();
        }
    }

    /**
     * Search memories by semantic similarity
     *
     * @param string $query
     * @return string
     */
    public function search(string $query): string
    {
        if (!$this->isEnabled()) {
            return "Error: Vector memory plugin is disabled.";
        }

        try {
            $result = $this->vectorMemoryService->searchVectorMemories($this->preset, $query, $this->config);

            if (!$result['success']) {
                return $result['message'];
            }

            if (empty($result['results'])) {
                return "No similar memories found for query: '{$query}'. Try broader search terms.";
            }

            $output = "Found " . count($result['results']) . " similar memories for '{$query}':\n\n";

            foreach ($result['results'] as $searchResult) {
                $similarity = round($searchResult['similarity'] * 100, 1);
                $date = $searchResult['memory']->created_at->format('M j, H:i');
                $truncateLength = $this->config['display_content_length'] ?? 500;
                $content = $this->truncateContent($searchResult['memory']->content, $truncateLength);
                $id = $searchResult['memory']->id;
                $output .= "• [ID:{$id}, {$similarity}% match, {$date}] {$content}\n";
            }

            return $output;

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::search error: " . $e->getMessage());
            return "Error searching memories: " . $e->getMessage();
        }
    }

    /**
     * Show recent memories
     *
     * @param string $limitStr
     * @return string
     */
    public function recent(string $limitStr): string
    {
        if (!$this->isEnabled()) {
            return "Error: Vector memory plugin is disabled.";
        }

        try {
            $limit = $limitStr !== '' ? max(1, min((int) $limitStr, 20)) : 5;
            $result = $this->vectorMemoryService->getRecentVectorMemories($this->preset, $limit);

            if (!$result['success']) {
                return $result['message'];
            }

            $memories = $result['memories'];

            if ($memories->isEmpty()) {
                return "No memories stored yet.";
            }

            $output = "Recent {$memories->count()} memories:\n\n";

            foreach ($memories as $memory) {
                $date = $memory->created_at->format('M j, H:i');
                $truncateLength = $this->config['display_content_length'] ?? 500;
                $content = $this->truncateContent($memory->content, $truncateLength);
                $features = count($memory->tfidf_vector);

                $output .= "• [ID:{$memory->id}, {$date}, {$features} features] {$content}\n";
            }

            return $output;

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::recent error: " . $e->getMessage());
            return "Error retrieving recent memories: " . $e->getMessage();
        }
    }

    public function show(string $memoryId): string
    {
        if (!$this->isEnabled()) {
            return "Error: Vector memory plugin is disabled.";
        }

        try {
            $id = (int) $memoryId;
            $memory = $this->vectorMemoryService->getVectorMemoryById($this->preset, $id);

            if (!$memory) {
                return "Memory with ID {$id} not found.";
            }

            $date = $memory->created_at->format('M j, H:i');
            $features = count($memory->tfidf_vector);
            $keywords = implode(', ', $memory->keywords ?? []);

            return "Memory ID {$id} [{$date}, {$features} features]:\n\n" .
                "{$memory->content}\n\n" .
                "Keywords: {$keywords}";

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::show error: " . $e->getMessage());
            return "Error showing memory: " . $e->getMessage();
        }
    }

    /**
     * Clear all vector memories
     *
     * @param string $content
     * @return string
     */
    public function clear(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Vector memory plugin is disabled.";
        }

        try {
            $result = $this->vectorMemoryService->clearVectorMemories($this->preset);
            return $result['message'];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::clear error: " . $e->getMessage());
            return "Error clearing memories: " . $e->getMessage();
        }
    }

    /**
     * Delete specific vector memory by ID or content search
     *
     * @param string $identifier Memory ID or content fragment to search for
     * @return string
     */
    public function delete(string $identifier): string
    {
        if (!$this->isEnabled()) {
            return "Error: Vector memory plugin is disabled.";
        }

        try {
            $identifier = trim($identifier);

            if (empty($identifier)) {
                return "Error: Please provide memory ID or content to search for deletion.";
            }

            // Try to parse as ID first
            if (is_numeric($identifier)) {
                $id = (int) $identifier;
                if ($id > 0) {
                    $result = $this->vectorMemoryService->deleteVectorMemory($this->preset, $id);
                    return $result['message'];
                }
            }

            // If not a valid ID, search by content
            $searchResult = $this->vectorMemoryService->searchVectorMemories($this->preset, $identifier, [
                'search_limit' => 1,
                'similarity_threshold' => 0.3
            ]);

            if (!$searchResult['success'] || empty($searchResult['results'])) {
                return "No memory found matching '{$identifier}'. Try using exact ID or different search terms.";
            }

            $memory = $searchResult['results'][0]['memory'];
            $similarity = round($searchResult['results'][0]['similarity'] * 100, 1);

            $deleteResult = $this->vectorMemoryService->deleteVectorMemory($this->preset, $memory->id);

            if ($deleteResult['success']) {
                $preview = $this->truncateContent($memory->content, 60);
                return "Deleted memory (ID:{$memory->id}, {$similarity}% match): {$preview}";
            }

            return $deleteResult['message'];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::delete error: " . $e->getMessage());
            return "Error deleting memory: " . $e->getMessage();
        }
    }

    /**
     * Get vector memory service instance for external use
     *
     * @return VectorMemoryServiceInterface
     */
    public function getVectorMemoryService(): VectorMemoryServiceInterface
    {
        return $this->vectorMemoryService;
    }

    /**
     * Add reference to regular memory plugin
     *
     * @param \App\Models\VectorMemory $vectorMemory
     * @return string|null
     */
    private function addToRegularMemory($vectorMemory): ?string
    {
        try {
            // Get memory plugin instance
            $memoryPlugin = $this->getMemoryPlugin();

            if (!$memoryPlugin) {
                $this->logger->warning("Memory plugin not found. Cannot add vector memory reference.");
                return null;
            }

            $format = $this->config['memory_link_format'] ?? 'descriptive';
            $maxKeywords = $this->config['max_link_keywords'] ?? 4;

            // Limit keywords
            $limitedKeywords = array_slice($vectorMemory->keywords ?? [], 0, $maxKeywords);

            $link = $this->formatMemoryLink($vectorMemory, $limitedKeywords, $format);

            // Add to memory using the memory service
            $result = $this->memoryService->addMemoryItem($this->preset, $link);

            return "Added reference to regular memory.";

        } catch (\Throwable $e) {
            $this->logger->warning("Failed to add vector memory reference to regular memory: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get memory plugin instance
     *
     * @return MemoryPlugin|null
     */
    private function getMemoryPlugin(): ?MemoryPlugin
    {
        /* @var MemoryPlugin $memoryPlugin */
        $memoryPlugin = app(PluginRegistryInterface::class)->get('memory');
        $memoryPlugin?->setCurrentPreset($this->preset);
        return $memoryPlugin;
    }

    /**
     * Format memory link based on selected format
     *
     * @param \App\Models\VectorMemory $vectorMemory
     * @param array $keywords
     * @param string $format
     * @return string
     */
    private function formatMemoryLink($vectorMemory, array $keywords, string $format): string
    {
        $keywordsStr = implode(', ', $keywords);
        $shortContent = $this->truncateContent($vectorMemory->content, 50);

        return match($format) {
            'short' => "Vector: {$keywordsStr}",
            'timestamped' => "[{$vectorMemory->created_at->format('m-d H:i')}] Vector: {$keywordsStr}",
            'descriptive' => "Vector memory about: {$shortContent} (search: [vectormemory search]{$keywordsStr}[/vectormemory])",
            default => "Vector: {$keywordsStr}"
        };
    }

    /**
     * Truncate content for display with proper UTF-8 support and word boundaries
     *
     * @param string $content Content to truncate
     * @param int $length Maximum length in characters (not bytes)
     * @param bool $respectWordBoundaries Whether to avoid cutting words in the middle
     * @return string Truncated content
     */
    private function truncateContent(string $content, int $length, bool $respectWordBoundaries = true): string
    {
        // Use mb_strlen for proper UTF-8 character counting
        if (mb_strlen($content, 'UTF-8') <= $length) {
            return $content;
        }

        // Truncate using mb_substr for proper UTF-8 handling
        $truncated = mb_substr($content, 0, $length, 'UTF-8');

        if ($respectWordBoundaries) {
            // Find the last space to avoid cutting words in the middle
            $lastSpace = mb_strrpos($truncated, ' ', 0, 'UTF-8');

            // If we found a space and it's not too close to the beginning
            if ($lastSpace !== false && $lastSpace > ($length * 0.7)) {
                $truncated = mb_substr($truncated, 0, $lastSpace, 'UTF-8');
            }
        }

        return $truncated . '...';
    }

    /**
     * @inheritDoc
     */
    public function getMergeSeparator(): ?string
    {
        return "\n";
    }

    /**
     * @inheritDoc
     */
    public function canBeMerged(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function pluginReady(): void
    {
        // Nothing to do here
    }

    /**
     * @inheritDoc
     */
    public function getSelfClosingTags(): array
    {
        return ['clear'];
    }
}

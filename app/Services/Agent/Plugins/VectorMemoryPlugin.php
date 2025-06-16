<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use App\Models\VectorMemory;
use Psr\Log\LoggerInterface;
use App\Services\Agent\Plugins\MemoryPlugin;

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

    public function __construct(
        protected LoggerInterface $logger,
        protected TfIdfServiceInterface $tfIdfService,
        protected VectorMemory $vectorMemoryModel
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
            'Clear all memories: [vectormemory clear][/vectormemory]'
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
            'integrate_with_memory' => false, // Add reference to regular memory
            'memory_link_format' => 'descriptive', //short, descriptive, timestamped
            'max_link_keywords' => 4, // Max keywords in memory link
            'language_mode' => 'auto',
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
            // Test database connection and TF-IDF service
            $testContent = 'Vector memory test - ' . time();
            $vector = $this->tfIdfService->vectorize($testContent);

            return is_array($vector) && !empty($vector);
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
            $content = trim($content);
            if (empty($content)) {
                return "Error: Cannot store empty content.";
            }

            // Check if we need to cleanup old entries
            $this->cleanupIfNeeded();

            // Configure TF-IDF service with custom language settings
            $this->configureTfIdfService();

            // Generate TF-IDF vector
            $language = $this->determineLanguage($content);
            $vector = $this->tfIdfService->vectorize($content);
            $keywords = $this->extractKeywords($content, $language);

            // Store in database
            $vectorMemory = $this->vectorMemoryModel->create([
                'preset_id' => $this->preset->id,
                'content' => $content,
                'tfidf_vector' => $vector,
                'keywords' => $keywords,
                'importance' => 1.0
            ]);

            $result = "Content stored in vector memory successfully. Generated " . count($vector) . " features (language: {$language}).";

            // Add to regular memory if integration is enabled
            if ($this->config['integrate_with_memory'] ?? false) {
                $memoryLinkResult = $this->addToRegularMemory($vectorMemory, $keywords);
                if ($memoryLinkResult) {
                    $result .= " " . $memoryLinkResult;
                }
            }

            return $result;

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
            $query = trim($query);
            if (empty($query)) {
                return "Error: Search query cannot be empty.";
            }

            $memories = $this->vectorMemoryModel->where('preset_id', $this->preset->id)
                ->orderBy('created_at', 'desc')
                ->get();

            if ($memories->isEmpty()) {
                return "No memories found. Store some content first using [vectormemory]content[/vectormemory].";
            }

            $results = $this->tfIdfService->findSimilar(
                $query,
                $memories,
                $this->config['search_limit'] ?? 5,
                $this->config['similarity_threshold'] ?? 0.1,
                $this->config['boost_recent'] ?? true
            );

            if (empty($results)) {
                return "No similar memories found for query: '{$query}'. Try broader search terms.";
            }

            $output = "Found " . count($results) . " similar memories for '{$query}':\n\n";

            foreach ($results as $result) {
                $similarity = round($result['similarity'] * 100, 1);
                $date = $result['memory']->created_at->format('M j, H:i');
                $content = $this->truncateContent($result['memory']->content, 200);

                $output .= "• [{$similarity}% match, {$date}] {$content}\n";
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

            $memories = $this->vectorMemoryModel->where('preset_id', $this->preset->id)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            if ($memories->isEmpty()) {
                return "No memories stored yet.";
            }

            $output = "Recent {$memories->count()} memories:\n\n";

            foreach ($memories as $memory) {
                $date = $memory->created_at->format('M j, H:i');
                $content = $this->truncateContent($memory->content, 150);
                $features = count($memory->tfidf_vector);

                $output .= "• [{$date}, {$features} features] {$content}\n";
            }

            return $output;

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::recent error: " . $e->getMessage());
            return "Error retrieving recent memories: " . $e->getMessage();
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
            $count = $this->vectorMemoryModel->where('preset_id', $this->preset->id)->count();
            $this->vectorMemoryModel->where('preset_id', $this->preset->id)->delete();

            return "Cleared {$count} vector memories successfully.";

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryPlugin::clear error: " . $e->getMessage());
            return "Error clearing memories: " . $e->getMessage();
        }
    }

    /**
     * Add reference to regular memory plugin
     *
     * @param VectorMemory $vectorMemory
     * @param array $keywords
     * @return string|null
     */
    private function addToRegularMemory(VectorMemory $vectorMemory, array $keywords): ?string
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
            $limitedKeywords = array_slice($keywords, 0, $maxKeywords);

            $link = $this->formatMemoryLink($vectorMemory, $limitedKeywords, $format);

            // Add to memory using the memory plugin
            /* @var MemoryPlugin $memoryPlugin */
            $result = $memoryPlugin->append($link);

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
     * @param VectorMemory $vectorMemory
     * @param array $keywords
     * @param string $format
     * @return string
     */
    private function formatMemoryLink(VectorMemory $vectorMemory, array $keywords, string $format): string
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
     * Configure TF-IDF service with custom language settings
     *
     * @return void
     */
    private function configureTfIdfService(): void
    {
        $languageConfig = [];

        // Add custom Russian stop words
        if (!empty($this->config['custom_stop_words_ru'])) {
            $customRu = array_map('trim', explode(',', $this->config['custom_stop_words_ru']));
            $languageConfig['ru']['stop_words'] = array_merge(
                $languageConfig['ru']['stop_words'] ?? [],
                $customRu
            );
        }

        // Add custom English stop words
        if (!empty($this->config['custom_stop_words_en'])) {
            $customEn = array_map('trim', explode(',', $this->config['custom_stop_words_en']));
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
     * @return string
     */
    private function determineLanguage(string $content): string
    {
        $mode = $this->config['language_mode'] ?? 'auto';

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
    private function extractKeywords(string $content, string $language = 'auto'): array
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
     * @return void
     */
    private function cleanupIfNeeded(): void
    {
        if (!($this->config['auto_cleanup'] ?? true)) {
            return;
        }

        $maxEntries = $this->config['max_entries'] ?? 1000;
        $currentCount = $this->vectorMemoryModel->where('preset_id', $this->preset->id)->count();

        if ($currentCount >= $maxEntries) {
            $deleteCount = $currentCount - $maxEntries + 1;

            $this->vectorMemoryModel->where('preset_id', $this->preset->id)
                ->orderBy('created_at', 'asc')
                ->limit($deleteCount)
                ->delete();
        }
    }

    /**
     * Truncate content for display
     *
     * @param string $content
     * @param int $length
     * @return string
     */
    private function truncateContent(string $content, int $length): string
    {
        if (strlen($content) <= $length) {
            return $content;
        }

        return substr($content, 0, $length) . '...';
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
}

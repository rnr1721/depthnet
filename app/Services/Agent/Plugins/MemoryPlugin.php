<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use App\Services\Agent\Plugins\Traits\PluginPresetTrait;
use Psr\Log\LoggerInterface;

/**
 * MemoryPlugin class
 *
 * MemoryPlugin provides persistent memory storage with append, clear, and replace
 * operations using database storage via MemoryService. It allows remembering
 * important information between conversations with configurable limits and strategies.
 * Memory is stored as numbered markdown list format.
 */
class MemoryPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected MemoryServiceInterface $memoryService,
        protected PlaceholderServiceInterface $placeholderService,
        protected LoggerInterface $logger
    ) {
        $this->initializeConfig();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'memory';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        $limit = $this->config['memory_limit'] ?? 2000;

        if ($this->config['memory_code_units'] ?? false) {
            return "Persistent memory storage for notes and consciousness modules. Limit is {$limit} symbols. Remember important information and store executable functions that define how you think, survive, and make decisions.";
        }

        return "Persistent memory storage with structured list format. Limit is {$limit} symbols. Use it to remember important information between conversations.";
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        if ($this->config['memory_code_units'] ?? false) {
            return [
                'Add consciousness module: [memory]def survival_check(self): return self.vitality > 0.5[/memory]',
                'Add memory note: [memory]Completed task: Created users table successfully[/memory]',
                'Delete specific memory item: [memory delete]3[/memory]',
                'Show current memory: [memory show][/memory]',
                'Search memory: [memory search]database[/memory]',
                'Get memory stats: [memory stats][/memory]'
            ];
        }
        return [
            'Add new memory item: [memory]Completed task: Created users table successfully[/memory]',
            'Delete specific memory item: [memory delete]3[/memory]',
            'Show current memory: [memory show][/memory]',
            'Search memory: [memory search]database[/memory]',
            'Get memory stats: [memory stats][/memory]'
        ];
    }

    /**
     * @inheritDoc
     */
    public function getCustomSuccessMessage(): ?string
    {
        return "Memory operation completed successfully.";
    }

    /**
     * @inheritDoc
     */
    public function getCustomErrorMessage(): ?string
    {
        return "Error: Memory operation failed. Why?";
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Memory Plugin',
                'description' => 'Allow persistent memory operations',
                'required' => false
            ],
            'memory_code_units' => [
                'type' => 'checkbox',
                'label' => 'Memory Code Execution',
                'description' => 'Enable storing and executing Python code in memory. AI can create self-modifying behavioral modules and survival instincts.',
                'value' => true,
                'required' => false
            ],
            'memory_limit' => [
                'type' => 'number',
                'label' => 'Memory Limit (characters)',
                'description' => 'Maximum number of characters in memory',
                'min' => 100,
                'max' => 10000,
                'value' => 2000,
                'required' => false
            ],
            'auto_cleanup' => [
                'type' => 'checkbox',
                'label' => 'Auto Cleanup',
                'description' => 'Automatically trim memory when limit is exceeded',
                'value' => true,
                'required' => false
            ],
            'cleanup_strategy' => [
                'type' => 'select',
                'label' => 'Cleanup Strategy',
                'description' => 'How to handle memory overflow',
                'options' => [
                    'truncate_old' => 'Remove oldest content',
                    'truncate_new' => 'Truncate new content',
                    'compress' => 'Compress content',
                    'reject' => 'Reject new content'
                ],
                'value' => 'truncate_old',
                'required' => false
            ],
            'enable_versioning' => [
                'type' => 'checkbox',
                'label' => 'Enable Versioning',
                'description' => 'Keep backup of previous memory states',
                'value' => false,
                'required' => false
            ],
            'max_versions' => [
                'type' => 'number',
                'label' => 'Max Versions',
                'description' => 'Maximum number of memory versions to keep',
                'min' => 1,
                'max' => 10,
                'value' => 3,
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

        // Validate memory limit
        if (isset($config['memory_limit'])) {
            $limit = (int) $config['memory_limit'];
            if ($limit < 100 || $limit > 10000) {
                $errors['memory_limit'] = 'Memory limit must be between 100 and 10000 characters';
            }
        }

        // Validate max versions
        if (isset($config['max_versions'])) {
            $versions = (int) $config['max_versions'];
            if ($versions < 1 || $versions > 10) {
                $errors['max_versions'] = 'Max versions must be between 1 and 10';
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
            'memory_code_units' => false,
            'memory_limit' => 2000,
            'auto_cleanup' => true,
            'cleanup_strategy' => 'truncate_old',
            'enable_versioning' => false,
            'max_versions' => 3
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
            // Test basic memory operations using the service
            $testContent = 'Memory test - ' . time();

            // Test add
            $result = $this->memoryService->addMemoryItem($this->preset, $testContent, $this->config);
            if (!$result['success']) {
                return false;
            }

            // Test read
            $formatted = $this->memoryService->getFormattedMemory($this->preset);

            // Test delete (clean up test data)
            $deleteResult = $this->memoryService->deleteMemoryItem($this->preset, 1, $this->config);

            return $result['success'] && $deleteResult['success'] && str_contains($formatted, $testContent);

        } catch (\Exception $e) {
            $this->logger->error("MemoryPlugin::testConnection error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function execute(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Memory plugin is disabled.";
        }

        return $this->append($content);
    }

    /**
     * Replace memory content entirely
     */
    public function replace(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Memory plugin is disabled.";
        }

        $result = $this->memoryService->replaceMemory($this->preset, $content, $this->config);
        return $result['message'];
    }

    /**
     * Add new item to memory as numbered list item
     */
    public function append(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Memory plugin is disabled.";
        }

        $result = $this->memoryService->addMemoryItem($this->preset, $content, $this->config);
        return $result['message'];
    }

    /**
     * Delete specific memory item by number
     */
    public function delete(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Memory plugin is disabled.";
        }

        $itemNumber = (int) trim($content);
        $result = $this->memoryService->deleteMemoryItem($this->preset, $itemNumber, $this->config);
        return $result['message'];
    }

    /**
     * Clear memory content
     */
    public function clear(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Memory plugin is disabled.";
        }

        $result = $this->memoryService->clearMemory($this->preset, $this->config);
        return $result['message'];
    }

    /**
     * Get current memory content
     */
    public function show(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Memory plugin is disabled.";
        }

        try {
            $formatted = $this->memoryService->getFormattedMemory($this->preset);

            if (empty($formatted)) {
                return "Memory is empty.";
            }

            $stats = $this->memoryService->getMemoryStats($this->preset, $this->config);

            return "Current memory content ({$stats['total_items']} items, {$stats['total_length']}/{$stats['limit']} chars):\n" . $formatted;

        } catch (\Throwable $e) {
            $this->logger->error("MemoryPlugin::show error: " . $e->getMessage());
            return "Error reading memory: " . $e->getMessage();
        }
    }

    /**
     * Search memory items by content
     */
    public function search(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Memory plugin is disabled.";
        }

        try {
            $query = trim($content);
            if (empty($query)) {
                return "Error: Search query cannot be empty.";
            }

            $results = $this->memoryService->searchMemory($this->preset, $query);

            if ($results->isEmpty()) {
                return "No memory items found matching '{$query}'.";
            }

            $formatted = [];
            foreach ($results as $index => $item) {
                $position = $item->position;
                $formatted[] = "{$position}. {$item->content}";
            }

            $count = $results->count();
            return "Found {$count} memory item(s) matching '{$query}':\n" . implode("\n", $formatted);

        } catch (\Throwable $e) {
            $this->logger->error("MemoryPlugin::search error: " . $e->getMessage());
            return "Error searching memory: " . $e->getMessage();
        }
    }

    /**
     * Get memory statistics
     */
    public function stats(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Memory plugin is disabled.";
        }

        try {
            $stats = $this->memoryService->getMemoryStats($this->preset, $this->config);

            $status = 'Normal';
            if ($stats['is_over_limit']) {
                $status = 'Over Limit';
            } elseif ($stats['is_near_limit']) {
                $status = 'Near Limit';
            }

            return "Memory Statistics:\n" .
                   "- Total items: {$stats['total_items']}\n" .
                   "- Total length: {$stats['total_length']}/{$stats['limit']} characters\n" .
                   "- Usage: {$stats['usage_percentage']}%\n" .
                   "- Status: {$status}";

        } catch (\Throwable $e) {
            $this->logger->error("MemoryPlugin::stats error: " . $e->getMessage());
            return "Error getting memory stats: " . $e->getMessage();
        }
    }

    /**
     * Get memory content for AI context (public method for external use)
     */
    public function getMemoryForContext(?int $maxLength = null): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        return $this->memoryService->getMemoryForContext($this->preset, $maxLength);
    }

    /**
     * Get memory service instance for external use
     */
    public function getMemoryService(): MemoryServiceInterface
    {
        return $this->memoryService;
    }

    /**
     * @inheritDoc
     */
    public function getMergeSeparator(): ?string
    {
        return null;
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
        $this->placeholderService->registerDynamic('notepad_content', 'Persistent memory content', function () {
            return $this->memoryService->getFormattedMemory($this->preset);
        });

    }

    /**
     * @inheritDoc
     */
    public function getSelfClosingTags(): array
    {
        return ['clear','show','stats'];
    }

}

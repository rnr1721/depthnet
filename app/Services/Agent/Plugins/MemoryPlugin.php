<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use Psr\Log\LoggerInterface;

/**
 * MemoryPlugin class
 *
 * MemoryPlugin provides persistent memory storage with append, clear, and replace
 * operations. It allows remembering important information between conversations with
 * configurable limits and strategies. Memory is stored as a numbered markdown list.
 */
class MemoryPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;
    use PluginConfigTrait;

    public function __construct(protected LoggerInterface $logger)
    {
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
        return "Persistent memory storage with structured list format. Limit is {$limit} symbols. Use it to remember important information between conversations.";
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        return [
            'Add new memory item: [memory]Completed task: Created users table successfully[/memory]',
            'Delete specific memory item: [memory delete]3[/memory]',
            'Replace entire memory content: [memory replace]User prefers PHP over Python. Database credentials saved.[/memory]',
            'Clear all memory: [memory clear][/memory]',
            'Show current memory: [memory show][/memory]'
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
            // Test basic memory operations
            $originalContent = $this->preset->notes;
            $testContent = 'Memory test - ' . time();

            // Test append
            $this->preset->notes = $testContent;
            $this->preset->save();

            // Test read
            $savedContent = $this->preset->fresh()->notes;

            // Restore original content
            $this->preset->notes = $originalContent;
            $this->preset->save();

            return $savedContent === $testContent;
        } catch (\Exception $e) {
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
     *
     * @param string $content
     * @return string
     */
    public function replace(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Memory plugin is disabled.";
        }

        try {
            if (!$this->checkMemoryLimit($content)) {
                return $this->handleMemoryOverflow($content, 'replace');
            }

            // Save version if enabled
            if ($this->config['enable_versioning'] ?? false) {
                $this->saveVersion();
            }

            $this->preset->notes = $content;
            $this->preset->save();

            return "Memory replaced successfully. New content stored.";
        } catch (\Throwable $e) {
            $this->logger->error("MemoryPlugin::replace error: " . $e->getMessage());
            return "Error replacing memory: " . $e->getMessage();
        }
    }

    /**
     * Add new item to memory as numbered list item
     *
     * @param string $content
     * @return string
     */
    public function append(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Memory plugin is disabled.";
        }

        try {
            $currentContent = $this->preset->notes;
            $memoryItems = $this->parseMemoryItems($currentContent);

            // Add new item
            $nextNumber = count($memoryItems) + 1;
            $memoryItems[] = trim($content);

            $newContent = $this->formatMemoryItems($memoryItems);

            // Check memory limit
            if (!$this->checkMemoryLimit($newContent)) {
                return $this->handleMemoryOverflow($content, 'append');
            }

            $this->preset->notes = $newContent;
            $this->preset->save();

            return "Memory item #{$nextNumber} added successfully.";
        } catch (\Throwable $e) {
            $this->logger->error("MemoryPlugin::append error: " . $e->getMessage());
            return "Error adding to memory: " . $e->getMessage();
        }
    }

    /**
     * Delete specific memory item by number
     *
     * @param string $content
     * @return string
     */
    public function delete(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Memory plugin is disabled.";
        }

        try {
            $itemNumber = (int) trim($content);
            if ($itemNumber < 1) {
                return "Error: Invalid item number. Must be a positive integer.";
            }

            $currentContent = $this->preset->notes;
            $memoryItems = $this->parseMemoryItems($currentContent);

            if ($itemNumber > count($memoryItems)) {
                return "Error: Item #{$itemNumber} does not exist. Memory has " . count($memoryItems) . " items.";
            }

            // Save version if enabled
            if ($this->config['enable_versioning'] ?? false) {
                $this->saveVersion();
            }

            // Remove item (array is 0-indexed, but we display 1-indexed)
            array_splice($memoryItems, $itemNumber - 1, 1);

            $newContent = $this->formatMemoryItems($memoryItems);
            $this->preset->notes = $newContent;
            $this->preset->save();

            return empty($memoryItems)
                ? "Memory item #{$itemNumber} deleted. Memory is now empty."
                : "Memory item #{$itemNumber} deleted successfully.";

        } catch (\Throwable $e) {
            $this->logger->error("MemoryPlugin::delete error: " . $e->getMessage());
            return "Error deleting memory item: " . $e->getMessage();
        }
    }

    /**
     * Clear memory content
     *
     * @param string $content
     * @return string
     */
    public function clear(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Memory plugin is disabled.";
        }

        try {
            if ($this->config['enable_versioning'] ?? false) {
                $this->saveVersion();
            }

            $this->preset->notes = '';
            $this->preset->save();

            return "Memory cleared successfully.";
        } catch (\Throwable $e) {
            $this->logger->error("MemoryPlugin::clear error: " . $e->getMessage());
            return "Error clearing memory: " . $e->getMessage();
        }
    }

    /**
     * Get current memory content
     *
     * @param string $content
     * @return string
     */
    public function show(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Memory plugin is disabled.";
        }

        try {
            $currentContent = $this->preset->notes;
            if (empty($currentContent)) {
                return "Memory is empty.";
            }

            $memoryItems = $this->parseMemoryItems($currentContent);
            $length = strlen($currentContent);
            $limit = $this->config['memory_limit'] ?? 2000;

            return "Current memory content (" . count($memoryItems) . " items, {$length}/{$limit} chars):\n" . $currentContent;
        } catch (\Throwable $e) {
            $this->logger->error("MemoryPlugin::show error: " . $e->getMessage());
            return "Error reading memory: " . $e->getMessage();
        }
    }

    /**
     * Parse memory content into array of items
     * Handles both old format (plain text) and new format (numbered list)
     *
     * @param string $content
     * @return array
     */
    private function parseMemoryItems(string $content): array
    {
        if (empty(trim($content))) {
            return [];
        }

        // Check if content is already in numbered list format
        if (preg_match('/^\d+\.\s/', trim($content))) {
            // Parse numbered list
            $lines = explode("\n", $content);
            $items = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match('/^\d+\.\s(.+)$/', $line, $matches)) {
                    $items[] = $matches[1];
                }
            }

            return $items;
        } else {
            // Old format - migrate to new format
            // Treat entire content as single item for now
            return [trim($content)];
        }
    }

    /**
     * Format memory items as numbered markdown list
     *
     * @param array $items
     * @return string
     */
    private function formatMemoryItems(array $items): string
    {
        if (empty($items)) {
            return '';
        }

        $formatted = [];
        foreach ($items as $index => $item) {
            $number = $index + 1;
            $formatted[] = "{$number}. {$item}";
        }

        return implode("\n", $formatted);
    }

    /**
     * Check if content fits within memory limit
     *
     * @param string $content
     * @return bool
     */
    private function checkMemoryLimit(string $content): bool
    {
        $limit = $this->config['memory_limit'] ?? 2000;
        return strlen($content) <= $limit;
    }

    /**
     * Handle memory overflow based on strategy
     *
     * @param string $newContent
     * @param string $operation
     * @return string
     */
    private function handleMemoryOverflow(string $newContent, string $operation): string
    {
        $strategy = $this->config['cleanup_strategy'] ?? 'truncate_old';
        $limit = $this->config['memory_limit'] ?? 2000;

        if (!($this->config['auto_cleanup'] ?? true)) {
            return "Error: Memory limit ({$limit} chars) exceeded. Auto cleanup is disabled.";
        }

        try {
            $currentContent = $this->preset->notes;
            $memoryItems = $this->parseMemoryItems($currentContent);

            switch ($strategy) {
                case 'truncate_old':
                    if ($operation === 'append') {
                        // Add new item and remove old ones until we fit
                        $memoryItems[] = trim($newContent);

                        while (count($memoryItems) > 0) {
                            $testContent = $this->formatMemoryItems($memoryItems);
                            if (strlen($testContent) <= $limit) {
                                break;
                            }
                            array_shift($memoryItems); // Remove oldest item
                        }
                    } else {
                        // For replace, just truncate the content
                        $newContent = substr($newContent, 0, $limit);
                        $memoryItems = [$newContent];
                    }
                    break;

                case 'truncate_new':
                    if ($operation === 'append') {
                        // Truncate new content to fit
                        $currentLength = strlen($this->formatMemoryItems($memoryItems));
                        $availableSpace = $limit - $currentLength - 10; // Leave some space for formatting
                        if ($availableSpace > 0) {
                            $truncatedNew = substr($newContent, 0, $availableSpace);
                            $memoryItems[] = $truncatedNew;
                        }
                    } else {
                        $memoryItems = [substr($newContent, 0, $limit)];
                    }
                    break;

                case 'reject':
                    return "Error: Memory limit ({$limit} chars) exceeded. New content rejected.";

                case 'compress':
                    // Compression: remove extra whitespace
                    if ($operation === 'append') {
                        $memoryItems[] = trim($newContent);
                    } else {
                        $memoryItems = [trim($newContent)];
                    }

                    // Compress all items
                    $memoryItems = array_map(function ($item) {
                        return preg_replace('/\s+/', ' ', trim($item));
                    }, $memoryItems);

                    // If still too long, truncate
                    $testContent = $this->formatMemoryItems($memoryItems);
                    if (strlen($testContent) > $limit) {
                        while (count($memoryItems) > 0) {
                            $testContent = $this->formatMemoryItems($memoryItems);
                            if (strlen($testContent) <= $limit) {
                                break;
                            }
                            array_shift($memoryItems);
                        }
                    }
                    break;
            }

            $finalContent = $this->formatMemoryItems($memoryItems);
            $this->preset->notes = $finalContent;
            $this->preset->save();

            return "Memory updated with overflow handling ({$strategy}). Content may have been modified to fit limit.";

        } catch (\Throwable $e) {
            return "Error handling memory overflow: " . $e->getMessage();
        }
    }

    /**
     * Save current memory state as version
     *
     * @return void
     */
    private function saveVersion(): void
    {
        // TODO: Implement versioning logic
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
        return true;
    }
}

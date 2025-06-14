<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use Psr\Log\LoggerInterface;

/**
 * MemoryPlugin class
 *
 * MemoryPlugin provides persistent memory storage with append, clear, and replace
 * operations. It allows remembering important information between conversations with
 * configurable limits and strategies.
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
        return "Persistent memory storage with append, clear, and replace operations. Limit is {$limit} symbols. Use it to remember important information between conversations.";
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        return [
            'Append new information to memory: [memory]Completed task: Created users table successfully[/memory]',
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
     * Append to existing memory content
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
            $newContent = empty($currentContent)
                ? $content
                : $currentContent . "\n" . $content;

            // Check memory limit
            if (!$this->checkMemoryLimit($newContent)) {
                return $this->handleMemoryOverflow($content, 'append');
            }

            $this->preset->notes = $newContent;
            $this->preset->save();

            return "Content appended to memory successfully.";
        } catch (\Throwable $e) {
            $this->logger->error("MemoryPlugin::append error: " . $e->getMessage());
            return "Error appending to memory: " . $e->getMessage();
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
            $length = strlen($currentContent);
            $limit = $this->config['memory_limit'] ?? 2000;

            if (empty($currentContent)) {
                return "Memory is empty.";
            }

            return "Current memory content ({$length}/{$limit} chars):\n" . $currentContent;
        } catch (\Throwable $e) {
            $this->logger->error("MemoryPlugin::show error: " . $e->getMessage());
            return "Error reading memory: " . $e->getMessage();
        }
    }

    /**
     * Check if content fits within memory limit
     *
     * @param string $content
     * @return boolean
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
            switch ($strategy) {
                case 'truncate_old':
                    if ($operation === 'append') {
                        // Keep only recent content that fits with new content
                        $availableSpace = $limit - strlen($newContent) - 1; // -1 for newline
                        if ($availableSpace > 0) {
                            $currentContent = $this->preset->notes;
                            $truncatedCurrent = substr($currentContent, -$availableSpace);
                            $this->preset->notes = $truncatedCurrent . "\n" . $newContent;
                        } else {
                            $this->preset->notes = substr($newContent, 0, $limit);
                        }
                    } else {
                        $this->preset->notes = substr($newContent, 0, $limit);
                    }
                    break;

                case 'truncate_new':
                    if ($operation === 'append') {
                        $currentContent = $this->preset->notes;
                        $availableSpace = $limit - strlen($currentContent) - 1;
                        if ($availableSpace > 0) {
                            $truncatedNew = substr($newContent, 0, $availableSpace);
                            $this->preset->notes = $currentContent . "\n" . $truncatedNew;
                        }
                    } else {
                        $this->preset->notes = substr($newContent, 0, $limit);
                    }
                    break;

                case 'reject':
                    return "Error: Memory limit ({$limit} chars) exceeded. New content rejected.";

                case 'compress':
                    // Compression: remove extra whitespace and newlines
                    $compressed = preg_replace('/\s+/', ' ', $newContent);
                    $compressed = trim($compressed);
                    if (strlen($compressed) <= $limit) {
                        $this->preset->notes = $compressed;
                    } else {
                        $this->preset->notes = substr($compressed, 0, $limit);
                    }
                    break;
            }

            $this->preset->save();
            return "Memory updated with overflow handling ({$strategy}). Content may have been truncated.";

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
